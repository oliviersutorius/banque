<?php

/**
 * Routeur de l'API. Toutes les réponses sont en JSON.
 */

declare(strict_types=1);

require __DIR__ . '/bootstrap.php';

// Fichiers et dossiers créés lisibles par le seul utilisateur du serveur (base, sauvegardes, journaux)
umask(0077);

use Banque\Account;
use Banque\AuditLog;
use Banque\Auth;
use Banque\AuthConfig;
use Banque\CategoryManager;
use Banque\CategoryRepository;
use Banque\Categorizer;
use Banque\CsvFormatException;
use Banque\CsvParser;
use Banque\Database;
use Banque\DatabaseSessionHandler;
use Banque\DefaultData;
use Banque\Direction;
use Banque\HttpException;
use Banque\Importer;
use Banque\LabelNormalizer;
use Banque\LoginThrottle;
use Banque\MonthLine;
use Banque\OperationRepository;
use Banque\Paths;
use Banque\PhpSession;
use Banque\RequestGuard;
use Banque\Router;
use Banque\Rule;
use Banque\RuleRepository;
use Banque\SecretBox;
use Banque\SettingsRepository;

const MAX_UPLOAD_BYTES = 2 * 1024 * 1024;
const MAX_BODY_BYTES = 10 * 1024 * 1024;
/** Routes accessibles sans être connecté (« MÉTHODE /chemin ») */
const PUBLIC_ROUTES = ['GET /api/session', 'POST /api/connexion', 'POST /api/connexion/code', 'POST /api/deconnexion'];

function sendJson(int $status, mixed $body): void
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    echo json_encode($body, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
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
 * @return list<int>
 */
function idList(mixed $value): array
{
    if (!is_array($value) || array_filter($value, 'is_int') !== $value) {
        throw new HttpException(400, '« autres » doit être une liste d\'identifiants');
    }

    return array_values($value);
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
// BANQUE_BASE : fichier SQLite (défaut base/banque.sqlite) ; BANQUE_LOG : dossier des journaux (défaut log/)
$paths = Paths::fromEnvironment(dirname(__DIR__));
$now = time();
$ip = is_string($_SERVER['REMOTE_ADDR'] ?? null) ? $_SERVER['REMOTE_ADDR'] : '';

try {
    $guard->check($_SERVER);
    $db = Database::open($paths);

    $normalizer = new LabelNormalizer();
    $categories = new CategoryRepository($db);
    $months = new OperationRepository($db, $categories);
    $rules = new RuleRepository($db, $categories);
    $settings = new SettingsRepository($db);
    $categoryManager = new CategoryManager($db, $categories, $months, $rules);
    $catalog = static fn () => $categories->catalog();
    $config = AuthConfig::fromServer($_SERVER, getenv('BANQUE_TOTP'));
    $auth = new Auth(
        new PhpSession(new DatabaseSessionHandler($db, Auth::ABSOLUTE_SECONDS), $config->local(), Auth::ABSOLUTE_SECONDS),
        new Account($db, SecretBox::fromEnvironment()),
        new LoginThrottle($db),
        $config,
        new AuditLog($paths->connectionLog()),
    );
    $router = new Router();

    $router->add('GET', '/api/sante', static fn (): array => ['statut' => 'ok']);

    $router->add('GET', '/api/session', static fn (): array => $auth->status($now));
    $router->add('POST', '/api/connexion', static function () use ($auth, $ip, $now): array {
        $body = jsonBody();

        return $auth->loginWithPassword(
            is_string($body['identifiant'] ?? null) ? $body['identifiant'] : '',
            is_string($body['mot_de_passe'] ?? null) ? $body['mot_de_passe'] : '',
            $ip,
            $now,
        );
    });
    $router->add('POST', '/api/connexion/code', static function () use ($auth, $ip, $now): array {
        $body = jsonBody();

        return $auth->loginWithCode(is_string($body['code'] ?? null) ? $body['code'] : '', $ip, $now);
    });
    $router->add('POST', '/api/deconnexion', static function () use ($auth, $ip, $now): array {
        $auth->logout($ip, $now);

        return ['connecte' => false];
    });

    $importer = static function () use ($rules, $catalog, $normalizer, $months): Importer {
        $categoryCatalog = $catalog();

        return new Importer(new CsvParser(), new Categorizer($rules->all(), $categoryCatalog, $normalizer), $normalizer, $months, $categoryCatalog);
    };
    $router->add('POST', '/api/import/preview', static fn (): array => $importer()->preview(uploadedCsv()));
    $router->add('POST', '/api/import/confirm', static fn (): array => $importer()->confirm(jsonBody()));

    $router->add('GET', '/api/mois', static fn (): array => $months->months());
    $router->add('GET', '/api/mois/{mois:\d{6}}', static function (array $p) use ($months, $normalizer): array {
        if (!OperationRepository::isValidMonth($p['mois']) || !$months->exists($p['mois'])) {
            throw new HttpException(404, 'Mois introuvable : ' . $p['mois']);
        }

        return monthResponse($p['mois'], $months->lines($p['mois']), $normalizer);
    });
    // Recatégorisation : { catégorie, autres?: [id…] } ; « autres » corrige aussi les lignes similaires en une fois.
    // Réponse : le mois de l'opération, à jour.
    $router->add('PATCH', '/api/operations/{id:\d+}', static function (array $p) use ($months, $catalog, $normalizer): array {
        $line = $months->find((int) $p['id']) ?? throw new HttpException(404, 'Opération introuvable : rechargez la page');
        $body = jsonBody();
        $direction = MonthLine::direction($line);
        $category = MonthLine::category($body['catégorie'] ?? null, $direction, $catalog());
        $months->recategorize([(int) $p['id'], ...idList($body['autres'] ?? [])], $category, $direction);
        $month = (string) $months->find((int) $p['id'])['date'];
        $month = substr($month, 6, 4) . substr($month, 3, 2);

        return monthResponse($month, $months->lines($month), $normalizer);
    });
    $router->add('GET', '/api/annee/{annee:\d{4}}', static function (array $p) use ($months, $normalizer): array {
        $result = [];
        foreach ($months->year($p['annee']) as $month => $lines) {
            $result[] = monthResponse((string) $month, $lines, $normalizer);
        }

        return ['année' => $p['annee'], 'mois' => $result];
    });
    $router->add('GET', '/api/a-trier', static function () use ($months, $normalizer): array {
        $result = [];
        foreach ($months->uncategorized() as $month => $lines) {
            $result[] = monthResponse((string) $month, $lines, $normalizer);
        }

        return $result;
    });

    $router->add('GET', '/api/categories', static fn (): array => $categoryManager->all());
    // Une action par appel : { action, sens, chemin, nom?, icone?, sous_catégorie?, remplacement? }
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
    $router->add('POST', '/api/categories/usage', static function () use ($categoryManager): array {
        [$direction, $path] = categoryTarget(jsonBody());

        return $categoryManager->usage($direction, $path);
    });

    $router->add('GET', '/api/regles', static fn (): array => array_map(static fn (Rule $r): array => $r->toArray(), $rules->all()));
    // Règle apprise : { règle, appliquer_aux_mois } ; une règle apprise de mêmes conditions est remplacée
    $router->add('POST', '/api/regles', static function () use ($rules, $catalog, $normalizer, $months): array {
        $body = jsonBody();
        $rule = RuleRepository::learnedFromInput(is_array($body['règle'] ?? null) ? $body['règle'] : [], $catalog());
        $learned = $rules->learn($rule);
        $updated = ($body['appliquer_aux_mois'] ?? false) === true ? $months->applyRuleToUncategorized($rule, $normalizer) : 0;

        return ['règle' => $learned['règle']->toArray(), 'remplacée' => $learned['remplacée'], 'lignes_mises_à_jour' => $updated];
    });
    // Lignes (par exemple du même marchand) que la règle saisie reconnaîtrait, sans rien enregistrer
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
    // Test d'un libellé : catégorie obtenue et règle appliquée (avec son identifiant)
    $router->add('POST', '/api/regles/tester', static function () use ($rules, $catalog, $normalizer): array {
        $body = jsonBody();
        $libelle = is_string($body['libelle'] ?? null) ? $body['libelle'] : '';
        $direction = Direction::tryFrom(is_string($body['sens'] ?? null) ? $body['sens'] : '')
            ?? throw new HttpException(400, '« sens » doit valoir « dépense » ou « revenu »');
        $result = (new Categorizer($rules->all(), $catalog(), $normalizer))->categorize($libelle, $direction);
        $label = $normalizer->normalize($libelle);

        return [
            'type_operation' => $label->type,
            'marchand' => $label->merchant,
            'texte' => $label->text(),
            'catégorie' => $result->toJsonValue(),
            'source' => $result->source,
            'règle' => $result->rule?->toArray(),
        ];
    });
    $router->add('PUT', '/api/regles/{id:\d+}', static function (array $p) use ($rules, $catalog): array {
        $body = jsonBody();

        return $rules->update((int) $p['id'], is_array($body['règle'] ?? null) ? $body['règle'] : [], $catalog())->toArray();
    });
    $router->add('DELETE', '/api/regles/{id:\d+}', static function (array $p) use ($rules): array {
        $rules->delete((int) $p['id']);

        return ['supprimée' => true];
    });

    $router->add('GET', '/api/parametres', static fn (): array => $settings->all());
    $router->add('PUT', '/api/parametres', static function () use ($settings): array {
        $theme = jsonBody()['thème'] ?? null;
        if (!in_array($theme, DefaultData::THEMES, true)) {
            throw new HttpException(400, 'Thème inconnu (' . implode(', ', DefaultData::THEMES) . ')');
        }
        $settings->set('thème', $theme);

        return $settings->all();
    });

    $path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
    $path = is_string($path) ? rtrim($path, '/') : '';
    $method = strtoupper(is_string($_SERVER['REQUEST_METHOD'] ?? null) ? $_SERVER['REQUEST_METHOD'] : 'GET');
    if (!in_array($method . ' ' . $path, PUBLIC_ROUTES, true)) {
        $auth->requireUser($now);
        $auth->checkCsrf($_SERVER);
        if (!in_array($method, ['GET', 'HEAD', 'OPTIONS'], true)) {
            // Une copie de la base par jour, avant la première modification
            $db->backupOncePerDay($paths->backupsDir());
        }
    }
    sendJson(200, $router->dispatch($method, $path));
} catch (HttpException $e) {
    sendJson($e->status(), ['erreur' => $e->getMessage()] + $e->details());
} catch (InvalidArgumentException | CsvFormatException $e) {
    sendJson(422, ['erreur' => $e->getMessage()]);
} catch (Throwable $e) {
    // Le détail (chemins, messages internes) reste dans le journal du serveur ; le client reçoit une référence
    $reference = bin2hex(random_bytes(4));
    error_log('[banque ' . $reference . '] ' . $e);
    sendJson(500, ['erreur' => 'Erreur interne (référence ' . $reference . ')', 'reference' => $reference]);
}
