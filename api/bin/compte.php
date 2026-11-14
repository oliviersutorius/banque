<?php

/**
 * Gestion du compte unique de l'application, en ligne de commande (il n'y a pas de route d'inscription).
 *
 *   php api/bin/compte.php init [--sans-totp]   crée le compte (remplace l'existant après confirmation)
 *   php api/bin/compte.php mot-de-passe          change le mot de passe
 *   php api/bin/compte.php totp-reset            nouveau secret TOTP et nouveaux codes de secours
 *   php api/bin/compte.php debloquer             lève les verrouillages après trop d'échecs
 *
 * Mêmes variables d'environnement que l'API : BANQUE_DATA_DIR, BANQUE_CLE ou BANQUE_CLE_FICHIER.
 * Sur le serveur, lancer le script avec l'utilisateur de PHP-FPM (sudo -u www-data …), sinon l'API ne pourra
 * pas lire les fichiers créés.
 */

declare(strict_types=1);

require dirname(__DIR__) . '/bootstrap.php';

use Banque\Account;
use Banque\JsonFile;
use Banque\LoginThrottle;
use Banque\SecretBox;
use Banque\Storage;
use Banque\Totp;

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

const ISSUER = 'Mes Comptes';
const USAGE = <<<TXT
    Usage : php api/bin/compte.php <commande>

      init [--sans-totp]  crée le compte ; --sans-totp pour le poste local uniquement
                          (à lancer avec BANQUE_TOTP=desactive, voir README)
      mot-de-passe        change le mot de passe
      totp-reset          nouveau secret TOTP et nouveaux codes de secours
      debloquer           lève les verrouillages après trop d'échecs de connexion

    TXT;

umask(0077);

function ask(string $question): string
{
    echo $question;
    $line = fgets(STDIN);

    return $line === false ? '' : rtrim($line, "\r\n");
}

/**
 * Saisie masquée si le terminal le permet.
 */
function askSecret(string $question): string
{
    $tty = stream_isatty(STDIN);
    if ($tty) {
        shell_exec('stty -echo');
    }
    try {
        $value = ask($question);
    } finally {
        if ($tty) {
            shell_exec('stty echo');
            echo "\n";
        }
    }

    return $value;
}

function newPassword(): string
{
    while (true) {
        $password = askSecret('Mot de passe (' . Account::MIN_PASSWORD_LENGTH . ' caractères minimum) : ');
        try {
            Account::validatePassword($password);
        } catch (InvalidArgumentException $e) {
            echo $e->getMessage(), "\n";
            continue;
        }
        if (askSecret('Confirmation : ') === $password) {
            return $password;
        }
        echo "Les deux saisies diffèrent.\n";
    }
}

function findExecutable(string $name): ?string
{
    foreach (explode(PATH_SEPARATOR, (string) getenv('PATH')) as $dir) {
        if ($dir !== '' && is_file($dir . '/' . $name) && is_executable($dir . '/' . $name)) {
            return $dir . '/' . $name;
        }
    }

    return null;
}

/**
 * QR code dans le terminal si qrencode est installé, sinon le secret à saisir à la main.
 *
 * @param list<string> $codes
 */
function showSecondFactor(string $secret, string $username, array $codes): void
{
    $uri = Totp::uri($secret, $username, ISSUER);
    echo "\n=== Double authentification ===\n";
    echo "Dans l'application d'authentification (Aegis, Google Authenticator, 1Password…), ajouter un compte :\n\n";

    $qrencode = findExecutable('qrencode');
    $process = $qrencode === null ? false : proc_open([$qrencode, '-t', 'ANSIUTF8', '-m', '2'], [0 => ['pipe', 'r'], 1 => STDOUT, 2 => ['pipe', 'w']], $pipes);
    $shown = false;
    if (is_resource($process)) {
        fwrite($pipes[0], $uri);
        fclose($pipes[0]);
        fclose($pipes[2]);
        $shown = proc_close($process) === 0;
    }
    if (!$shown) {
        echo "(installer « qrencode » pour afficher un QR code : sudo apt install qrencode)\n";
    }
    echo "\nSaisie manuelle — compte : $username, émetteur : " . ISSUER . ", type : basé sur le temps\n";
    echo 'Clé : ' . implode(' ', str_split(Totp::base32Encode($secret), 4)) . "\n";
    echo "Adresse : $uri\n";

    echo "\n=== Codes de secours ===\n";
    echo "Chacun permet UNE connexion sans le téléphone. À noter et ranger hors ligne : ils ne seront plus affichés.\n\n";
    foreach ($codes as $i => $code) {
        printf("  %2d. %s\n", $i + 1, $code);
    }
    echo "\nEffacer l'écran après les avoir notés (commande « clear »).\n";
}

/**
 * Vérifie que l'utilisateur a bien enregistré le secret avant de terminer.
 */
function confirmTotp(string $secret): void
{
    while (true) {
        $code = ask("\nPour vérifier, code affiché par l'application (Entrée pour passer) : ");
        if ($code === '') {
            return;
        }
        if (Totp::verify($secret, $code, time(), 0) !== null) {
            echo "Code correct : la double authentification est prête.\n";
            return;
        }
        echo "Code incorrect. Vérifier l'heure du téléphone et la clé saisie.\n";
    }
}

function requireBox(): SecretBox
{
    $box = SecretBox::fromEnvironment();
    if ($box === null) {
        fwrite(STDERR, "Clé de chiffrement absente : définir BANQUE_CLE ou BANQUE_CLE_FICHIER (voir php api/bin/cle.php).\n");
        exit(1);
    }

    return $box;
}

$command = $argv[1] ?? '';
$options = array_slice($argv, 2);
$storage = new Storage(getenv('BANQUE_DATA_DIR') ?: dirname(__DIR__, 2) . '/data');

try {
    $storage->ensureDirectories();
    $json = new JsonFile($storage->backupsDir());
    $withTotp = !in_array('--sans-totp', $options, true);
    $needsBox = in_array($command, ['totp-reset'], true) || ($command === 'init' && $withTotp);
    $account = new Account($storage->accountFile(), $json, $needsBox ? requireBox() : SecretBox::fromEnvironment());

    switch ($command) {
        case 'init':
            if ($account->exists() && strtolower(ask('Un compte existe déjà. Le remplacer ? (oui/non) ')) !== 'oui') {
                echo "Rien n'a été modifié.\n";
                exit(0);
            }
            $username = '';
            while (trim($username) === '') {
                $username = ask('Identifiant : ');
            }
            $created = $account->create($username, newPassword(), $withTotp);
            echo "Compte « " . trim($username) . " » créé.\n";
            if ($created['secret'] !== null) {
                showSecondFactor($created['secret'], trim($username), $created['codes']);
                confirmTotp($created['secret']);
            } else {
                echo "Sans double authentification : la connexion ne fonctionnera que sur le poste local, avec BANQUE_TOTP=desactive.\n";
                echo "Pour l'ajouter plus tard : php api/bin/compte.php totp-reset\n";
            }
            break;

        case 'mot-de-passe':
            if (!$account->exists()) {
                throw new RuntimeException('Aucun compte : lancer php api/bin/compte.php init');
            }
            $account->changePassword(newPassword());
            echo "Mot de passe changé. Les sessions ouvertes restent valides jusqu'à leur expiration (12 h au plus).\n";
            break;

        case 'totp-reset':
            if (!$account->exists()) {
                throw new RuntimeException('Aucun compte : lancer php api/bin/compte.php init');
            }
            if ($account->hasTotp() && strtolower(ask("L'ancien secret et les anciens codes de secours ne fonctionneront plus. Continuer ? (oui/non) ")) !== 'oui') {
                echo "Rien n'a été modifié.\n";
                exit(0);
            }
            $reset = $account->resetSecondFactor();
            showSecondFactor($reset['secret'], (string) $account->username(), $reset['codes']);
            confirmTotp($reset['secret']);
            break;

        case 'debloquer':
            (new LoginThrottle($storage->attemptsFile(), $json))->reset();
            echo "Verrouillages levés.\n";
            break;

        default:
            fwrite(STDERR, USAGE);
            exit($command === '' || $command === 'aide' ? 0 : 1);
    }
} catch (Throwable $e) {
    fwrite(STDERR, 'Erreur : ' . $e->getMessage() . "\n");
    exit(1);
}
