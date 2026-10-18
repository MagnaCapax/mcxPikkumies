<?php
/**
 * transcribe: speech to text for the user's own audio/video, cloud first (an OpenAI-compatible
 * endpoint the user logged in to) with a local whisper.cpp fallback (tools/media/README.md,
 * README.md here, ADR-0045). Never asks the user to paste a key into the chat: `login` reads it at
 * a terminal prompt. `run`'s output is special: the transcribed text on stdout, not a JSON record
 * (--json gives the record instead) — the only verb here that is not plain JSON Lines.
 * Test seams: a test may set $transcribeSecretDir, $transcribePrompt, $transcribePins and
 * $transcribeLocalDir before including this file.
 */
$lib = dirname(__DIR__, 3) . '/lib';
foreach (['Cli', 'CliHuman', 'Config', 'ToolProc', 'HomePath', 'Quota', 'SecretFile', 'Http'] as $class) {
    require_once "$lib/$class.php";
}
require_once dirname(__DIR__, 2) . '/storage/lib/ToolText.php'; // ToolText stayed in storage/lib (storage README)
require_once dirname(__DIR__) . '/lib/Ffmpeg.php';
foreach (['TranscribePins', 'TranscribeSecret', 'TranscribeCloud', 'TranscribeLocal', 'TranscribeRun', 'TranscribeInstall'] as $class) {
    require_once __DIR__ . "/$class.php";
}

$transcribeSecretDir = isset($transcribeSecretDir) ? $transcribeSecretDir : Config::localDir() . '/secrets';
$transcribePrompt = isset($transcribePrompt) ? $transcribePrompt : ['SecretFile', 'prompt'];
$transcribePins = isset($transcribePins) ? $transcribePins : null;
$transcribeLocalDir = isset($transcribeLocalDir) ? $transcribeLocalDir : null;
$secret = new TranscribeSecret($transcribeSecretDir);

$usage = <<<'TXT'
usage: php transcribe.php <verb> [arguments] [--human]
  run <file> [--language xx] [--json] [--local]   transcribes one file; prints the text (or --json the record)
  login                                            the user types endpoint, model and API key at a terminal
  status                                            cloud login present, local engine installed, ffmpeg found
  install-local [--model base|small]               builds the local whisper.cpp engine (never run on its own)
TXT;

Cli::run($argv, [
    // Prints the text on stdout (or --json the record) and exits itself: the one verb that is not plain JSON Lines.
    'run' => function ($args, $opt) use ($secret, $transcribeLocalDir) {
        $rec = TranscribeRun::run($args, $opt, $secret, $transcribeLocalDir);
        echo !empty($opt['json']) ? Cli::json($rec) : $rec['text'], "\n";
        exit(0);
    },
    'login' => function ($args, $opt) use ($secret, $transcribePrompt) {
        return $secret->promptAndSave($transcribePrompt);
    },
    'status' => function ($args, $opt) use ($secret, $transcribeLocalDir) {
        $login = null;
        try {
            $login = $secret->load();
        } catch (CliError $e) {
            // a broken secret file is reported through its own fields below, not thrown from status
        }
        $model = TranscribeLocal::installedModel($transcribeLocalDir);
        return [
            'cloud_login' => $login !== null,
            'cloud_endpoint_host' => $login !== null ? (string) parse_url($login['endpoint'], PHP_URL_HOST) : null,
            'cloud_model' => $login !== null ? $login['model'] : null,
            'local_installed' => TranscribeLocal::installed($transcribeLocalDir),
            'local_model' => $model,
            'local_model_bytes' => $model !== null ? @filesize(TranscribeLocal::modelPath($model, $transcribeLocalDir)) : null,
            'ffmpeg' => Ffmpeg::ffmpegPath() !== null,
        ];
    },
    'install-local' => function ($args, $opt) use ($transcribePins, $transcribeLocalDir) {
        return TranscribeInstall::run($args, $opt, $transcribePins, $transcribeLocalDir);
    },
], $usage, ['json', 'local'], ['language', 'model']);
