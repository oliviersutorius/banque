<?php

/**
 * Routeur de l'API. Toutes les réponses sont en JSON.
 */

declare(strict_types=1);

require __DIR__ . '/bootstrap.php';

// Fichiers et dossiers créés lisibles par le seul utilisateur du serveur (données bancaires, compte, sessions)
umask(0077);

use Banque\Account;
use Banque\AuditLog;
use Banque\Auth;
use Banque\AuthConfig;
use Banque\CategoryCatalog;
use Banque\CategoryManager;
use Banque\Categorizer;
use Banque\CsvFormatException;
use Banque\CsvParser;
use Banque\DefaultData;
use Banque\Direction;
use Banque\HttpException;
use Banque\Importer;
use Banque\JsonFile;
use Banque\LabelNormalizer;
use Banque\LineConflictException;
use Banque\LoginThrottle;
use Banque\MonthLine;
use Banque\MonthRepository;
use Banque\PhpSession;
use Banque\RequestGuard;
use Banque\Router;
use Banque\RuleRepository;
use Banque\SecretBox;
use Banque\Storage;

const MAX_UPLOAD_BYTES = 2 * 1024 * 1024;
const MAX_BODY_BYTES = 10 * 1024 * 1024;
/** Routes accessibles sans être connecté (« MÉTHODE /chemin ») */
const PUBLIC_ROUTES = ['GET /api/session', 'POST /api/connexion', 'POST /api/connexion/code', 'POST /api/deconnexion'];

function sendJson(int $status, mixed $body): void
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    echo json_encode($body, JsonFile::FLAGS | JSON_THROW_ON_ERROR);
}

/**
 * @return array<mixed>
 */
function jsonBody(): array
{
    RequestGuard::requireJson($_SERVER);
    $raw = file_get_contents('php://input', false, null, 0, MAX_BODY_BYTES + 1);
    if ($raw === false || strlen($raw) > MAX_BODY_BYTES) {
        throw new HttpException(413, 'Requête trop volumineuse');
    }
    $data = json_decode($raw, true);
    if (!is_array($data)) {
        throw new HttpException(400, 'Corps JSON attendu');
    }

    return $data;
}

function uploadedCsv(): string
{
    $file = $_FILES['fichier'] ?? null;
    if (!is_array($file) || ($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
        throw new HttpException(400, 'Aucun fichier reçu (champ « fichier »)');
    }
    if (in_array($file['error'], [UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE], true) || $file['size'] > MAX_UPLOAD_BYTES) {
        throw new HttpException(413, 'Fichier trop volumineux (2 Mo maximum)');
    }
    if ($file['error'] !== UPLOAD_ERR_OK || !is_uploaded_file($file['tmp_name'])) {
        throw new HttpException(400, 'Échec de l\'envoi du fichier');
    }

    return (string) file_get_contents($file['tmp_name']);
}

/**
 * Sens et chemin d'une catégorie dans un corps de requête : { sens, chemin: [catégorie1, catégorie2?] }.
 *
 * @param array<mixed> $body
 * @return array{Direction, list<string>}
 */
function categoryTarget(array $body, string $key = 'chemin'): array
{
    $direction = Direction::tryFrom(is_string($body['sens'] ?? null) ? $body['sens'] : '')
        ?? throw new HttpException(400, '« sens » doit valoir « dépense » ou « revenu »');
    $path = $body[$key] ?? null;
    if (!is_array($path) || $path === [] || count($path) > 2 || array_filter($path, 'is_string') !== $path) {
        throw new HttpException(400, '« ' . $key . ' » doit être une liste de 1 ou 2 noms');
    }

    return [$direction, array_values($path)];
}

/**
 * Contenu d'un mois, accompagné pour chaque ligne du type d'opération et du marchand normalisés
 * (utilisés pour pré-remplir le motif d'une règle).
 *
 * @param list<array<string, mixed>> $lines
 * @return array<string, mixed>
 */
function monthResponse(string $month, array $lines, LabelNormalizer $normalizer): array
{
    return [
        'mois' => $month,
        'lignes' => $lines,
        'analyse' => array_map(static function (array $line) use ($normalizer): array {
            $label = $normalizer->normalize((string) $line['libelle']);
            return ['type_operation' => $label->type, 'marchand' => $label->merchant];
        }, $lines),
    ];
}

// BANQUE_HOTES : noms d'hôte acceptés en plus de localhost, 127.0.0.1 et [::1] (séparés par des virgules)
$guard = RequestGuard::fromList(getenv('BANQUE_HOTES') ?: '');
// BANQUE_DATA_DIR permet de travailler sur un autre dossier de données (essais, tests)
$storage = new Storage(getenv('BANQUE_DATA_DIR') ?: dirname(__DIR__) . '/data');
$json = new JsonFile($storage->backupsDir());
$normalizer = new LabelNormalizer();
$months = new MonthRepository($storage->monthsDir(), $json);
$rules = new RuleRepository($storage->rulesFile(), $json);
$categoryManager = new CategoryManager($storage->categoriesFile(), $json, $months, $rules);
$catalog = static fn (): CategoryCatalog => CategoryCatalog::fromArray($json->read($storage->categoriesFile()));
$router = new Router();
$now = time();
$ip = is_string($_SERVER['REMOTE_ADDR'] ?? null) ? $_SERVER['REMOTE_ADDR'] : '';
// Créé dans le try : une clé de chiffrement invalide doit donner une erreur 500 propre
$auth = static function () use ($storage, $json): Auth {
    static $auth = null;
    if ($auth === null) {
        $config = AuthConfig::fromServer($_SERVER, getenv('BANQUE_TOTP'));
        $auth = new Auth(
            new PhpSession($storage->sessionsDir(), $config->local(), Auth::ABSOLUTE_SECONDS),
            new Account($storage->accountFile(), $json, SecretBox::fromEnvironment()),
            new LoginThrottle($storage->attemptsFile(), $json),
            $config,
            new AuditLog($storage->journalDir() . '/connexions.log'),
        );
    }

    return $auth;
};

$router->add('GET', '/api/sante', static fn (): array => ['statut' => 'ok']);

$router->add('GET', '/api/session', static fn (): array => $auth()->status($now));
$router->add('POST', '/api/connexion', static function () use ($auth, $ip, $now): array {
    $body = jsonBody();

    return $auth()->loginWithPassword(
        is_string($body['identifiant'] ?? null) ? $body['identifiant'] : '',
        is_string($body['mot_de_passe'] ?? null) ? $body['mot_de_passe'] : '',
        $ip,
        $now,
    );
});
$router->add('POST', '/api/connexion/code', static function () use ($auth, $ip, $now): array {
    $body = jsonBody();

    return $auth()->loginWithCode(is_string($body['code'] ?? null) ? $body['code'] : '', $ip, $now);
});
$router->add('POST', '/api/deconnexion', static function () use ($auth, $ip, $now): array {
    $auth()->logout($ip, $now);

    return ['connecte' => false];
});

$router->add('POST', '/api/import/preview', static function () use ($rules, $catalog, $normalizer, $months): array {
    $categories = $catalog();
    $importer = new Importer(new CsvParser(), new Categorizer($rules->all(), $categories, $normalizer), $normalizer, $months, $categories);

    return $importer->preview(uploadedCsv());
});
$router->add('POST', '/api/import/confirm', static function () use ($rules, $catalog, $normalizer, $months): array {
    $categories = $catalog();
    $importer = new Importer(new CsvParser(), new Categorizer($rules->all(), $categories, $normalizer), $normalizer, $months, $categories);

    return $importer->confirm(jsonBody());
});

$router->add('GET', '/api/mois', static fn (): array => $months->months());
$router->add('GET', '/api/mois/{mois:\d{6}}', static function (array $p) use ($months, $normalizer): array {
    if (!MonthRepository::isValidMonth($p['mois']) || !$months->exists($p['mois'])) {
        throw new HttpException(404, 'Mois introuvable : ' . $p['mois']);
    }

    return monthResponse($p['mois'], $months->lines($p['mois']), $normalizer);
});
// Recatégorisation : { date, libelle, catégorie, autres?: [{ index, date, libelle }] }
// « autres » permet de corriger aussi les lignes similaires du mois en une seule écriture
$router->add('PATCH', '/api/mois/{mois:\d{6}}/lignes/{index:\d+}', static function (array $p) use ($months, $catalog, $normalizer): array {
    if (!MonthRepository::isValidMonth($p['mois']) || !$months->exists($p['mois'])) {
        throw new HttpException(404, 'Mois introuvable : ' . $p['mois']);
    }
    $body = jsonBody();
    $target = static function (mixed $t): array {
        if (!is_array($t) || !is_int($t['index'] ?? null) || !is_string($t['date'] ?? null) || !is_string($t['libelle'] ?? null)) {
            throw new HttpException(400, 'Chaque ligne visée doit indiquer index, date et libelle');
        }
        return ['index' => $t['index'], 'date' => $t['date'], 'libelle' => $t['libelle']];
    };
    $targets = [$target(['index' => (int) $p['index']] + $body)];
    foreach (is_array($body['autres'] ?? null) ? $body['autres'] : [] as $other) {
        $targets[] = $target($other);
    }
    $line = $months->lines($p['mois'])[(int) $p['index']] ?? throw new HttpException(404, 'Ligne introuvable');
    $direction = MonthLine::direction($line);
    $category = MonthLine::category($body['catégorie'] ?? null, $direction, $catalog());

    return monthResponse($p['mois'], $months->recategorize($p['mois'], $targets, $category, $direction), $normalizer);
});

// Les mois enregistrés d'une année ; l'agrégation se fait côté front
$router->add('GET', '/api/annee/{annee:\d{4}}', static fn (array $p): array => [
    'année' => $p['annee'],
    'mois' => array_map(
        static fn (string $m): array => monthResponse($m, $months->lines($m), $normalizer),
        array_values(array_filter($months->months(), static fn (string $m): bool => str_starts_with($m, $p['annee']))),
    ),
]);
// Lignes encore « À catégoriser » de tous les mois, au même format que GET /api/mois/{YYYYMM}
// (index = position dans le mois, pour la recatégorisation par PATCH)
$router->add('GET', '/api/a-trier', static function () use ($months, $normalizer): array {
    $result = [];
    foreach ($months->months() as $month) {
        $all = monthResponse($month, $months->lines($month), $normalizer);
        $indexes = array_keys(array_filter($all['lignes'], [MonthLine::class, 'isUncategorized']));
        if ($indexes !== []) {
            $result[] = [
                'mois' => $month,
                'index' => $indexes,
                'lignes' => array_map(static fn (int $i): array => $all['lignes'][$i], $indexes),
                'analyse' => array_map(static fn (int $i): array => $all['analyse'][$i], $indexes),
            ];
        }
    }

    return $result;
});

$router->add('GET', '/api/categories', static fn (): array => $json->read($storage->categoriesFile()));
// Modifications : { action: ajouter | icone | renommer | supprimer, sens, chemin, nom?, icone?, sous_catégorie?, remplacement? }
// Le renommage et la suppression sont répercutés dans tous les mois et toutes les règles.
$router->add('PUT', '/api/categories', static function () use ($categoryManager): array {
    $body = jsonBody();
    [$direction, $path] = categoryTarget($body);
    $text = static fn (string $key): string => is_string($body[$key] ?? null) ? $body[$key] : '';
    $result = match ($body['action'] ?? null) {
        'ajouter' => $categoryManager->add($direction, $path, $text('icone'), $text('sous_catégorie')),
        'icone' => $categoryManager->changeIcon($direction, $path, $text('icone')),
        'renommer' => $categoryManager->rename($direction, $path, $text('nom')),
        'supprimer' => $categoryManager->delete(
            $direction,
            $path,
            isset($body['remplacement']) ? categoryTarget($body, 'remplacement')[1] : null,
        ),
        default => throw new HttpException(400, 'Action inconnue'),
    };

    return ['catégories' => $categoryManager->all(), 'modifiés' => $result ?? ['lignes' => 0, 'règles' => 0]];
});
// Nombre de lignes et de règles qui utilisent une catégorie, pour les confirmations
$router->add('POST', '/api/categories/usage', static function () use ($categoryManager): array {
    [$direction, $path] = categoryTarget(jsonBody());

    return $categoryManager->usage($direction, $path);
});

$router->add('GET', '/api/regles', static fn (): array => $json->read($storage->rulesFile()));
// Apprentissage : { règle, appliquer_aux_mois } → la règle est ajoutée (ou remplace une règle apprise identique)
$router->add('POST', '/api/regles', static function () use ($rules, $catalog, $normalizer, $months): array {
    $body = jsonBody();
    $rule = RuleRepository::learnedFromInput(is_array($body['règle'] ?? null) ? $body['règle'] : [], $catalog());
    $replaced = $rules->learn($rule);
    $updated = ($body['appliquer_aux_mois'] ?? false) === true ? $months->applyRuleToUncategorized($rule, $normalizer) : 0;

    return ['règle' => $rule->toArray(), 'remplacée' => $replaced, 'lignes_mises_à_jour' => $updated];
});
// Lignes similaires : { règle, lignes: [{ libelle, sens }] } → quelles lignes la règle reconnaît
$router->add('POST', '/api/regles/correspondances', static function () use ($catalog, $normalizer): array {
    $body = jsonBody();
    $rule = RuleRepository::learnedFromInput(is_array($body['règle'] ?? null) ? $body['règle'] : [], $catalog());
    $lines = is_array($body['lignes'] ?? null) ? $body['lignes'] : [];

    return ['correspondances' => array_map(static function (mixed $line) use ($rule, $normalizer): bool {
        $direction = Direction::tryFrom(is_array($line) && is_string($line['sens'] ?? null) ? $line['sens'] : '');
        $libelle = is_array($line) && is_string($line['libelle'] ?? null) ? $line['libelle'] : '';

        return $direction !== null && $rule->matches($normalizer->normalize($libelle), $direction);
    }, array_values($lines))];
});
// Test d'un libellé : { libelle, sens } → libellé normalisé et catégorie obtenue
$router->add('POST', '/api/regles/tester', static function () use ($rules, $catalog, $normalizer): array {
    $body = jsonBody();
    $libelle = is_string($body['libelle'] ?? null) ? $body['libelle'] : '';
    $direction = Direction::tryFrom(is_string($body['sens'] ?? null) ? $body['sens'] : '')
        ?? throw new HttpException(400, '« sens » doit valoir « dépense » ou « revenu »');
    $all = $rules->all();
    $result = (new Categorizer($all, $catalog(), $normalizer))->categorize($libelle, $direction);
    $label = $normalizer->normalize($libelle);
    $index = $result->rule === null ? false : array_search($result->rule, $all, true);

    return [
        'type_operation' => $label->type,
        'marchand' => $label->merchant,
        'texte' => $label->text(),
        'catégorie' => $result->toJsonValue(),
        'source' => $result->source,
        'règle' => $index === false ? null : ['index' => $index] + $all[$index]->toArray(),
    ];
});
// Modification et suppression : le motif actuel est vérifié pour éviter d'agir sur une autre règle
$router->add('PUT', '/api/regles/{index:\d+}', static function (array $p) use ($rules, $catalog): array {
    $body = jsonBody();
    $rule = $rules->update(
        (int) $p['index'],
        is_string($body['motif_actuel'] ?? null) ? $body['motif_actuel'] : '',
        is_array($body['règle'] ?? null) ? $body['règle'] : [],
        $catalog(),
    );

    return $rule->toArray();
});
$router->add('DELETE', '/api/regles/{index:\d+}', static function (array $p) use ($rules): array {
    $rules->delete((int) $p['index'], is_string($_GET['motif'] ?? null) ? $_GET['motif'] : '');

    return ['supprimée' => true];
});

$router->add('GET', '/api/parametres', static fn (): array => $json->read($storage->settingsFile()));
$router->add('PUT', '/api/parametres', static function () use ($json, $storage): array {
    $body = jsonBody();
    $theme = $body['thème'] ?? null;
    if (!in_array($theme, DefaultData::THEMES, true)) {
        throw new HttpException(400, 'Thème inconnu (' . implode(', ', DefaultData::THEMES) . ')');
    }
    $settings = ['thème' => $theme] + $json->read($storage->settingsFile());
    $json->write($storage->settingsFile(), $settings);

    return $settings;
});

try {
    $guard->check($_SERVER);
    $storage->ensureDirectories();
    $storage->initializeDefaults($json);
    $path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
    $path = is_string($path) ? rtrim($path, '/') : '';
    $method = strtoupper(is_string($_SERVER['REQUEST_METHOD'] ?? null) ? $_SERVER['REQUEST_METHOD'] : 'GET');
    if (!in_array($method . ' ' . $path, PUBLIC_ROUTES, true)) {
        $auth()->requireUser($now);
        $auth()->checkCsrf($_SERVER);
    }
    $result = $router->dispatch($method, $path);
    sendJson(200, $result);
} catch (HttpException $e) {
    sendJson($e->status(), ['erreur' => $e->getMessage()] + $e->details());
} catch (LineConflictException $e) {
    sendJson(409, ['erreur' => $e->getMessage()]);
} catch (InvalidArgumentException | CsvFormatException $e) {
    sendJson(422, ['erreur' => $e->getMessage()]);
} catch (Throwable $e) {
    // Le détail (chemins, messages internes) reste dans le journal du serveur ; le client reçoit une référence
    $reference = bin2hex(random_bytes(4));
    error_log('[banque ' . $reference . '] ' . $e);
    sendJson(500, ['erreur' => 'Erreur interne (référence ' . $reference . ')', 'reference' => $reference]);
}
