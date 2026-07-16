<?php

declare(strict_types=1);

if ($argc !== 3) {
    fwrite(STDERR, "Usage: php scripts/check-coverage.php <clover-file> <minimum-percent>\n");
    exit(2);
}

$coverageFile = $argv[1];
$minimumPercent = filter_var($argv[2], FILTER_VALIDATE_FLOAT);

if (! is_file($coverageFile) || $minimumPercent === false) {
    fwrite(STDERR, "The coverage file and minimum percentage must be valid.\n");
    exit(2);
}

$document = new DOMDocument;

if (! $document->load($coverageFile)) {
    fwrite(STDERR, "Unable to parse the Clover coverage report.\n");
    exit(2);
}

$metrics = $document->getElementsByTagName('project')->item(0)?->getElementsByTagName('metrics')->item(0);
$statements = (int) $metrics?->getAttribute('statements');
$coveredStatements = (int) $metrics?->getAttribute('coveredstatements');

if ($statements === 0) {
    fwrite(STDERR, "The Clover report does not contain executable statements.\n");
    exit(2);
}

$percentage = ($coveredStatements / $statements) * 100;
printf("Statement coverage: %.2f%% (required: %.2f%%)\n", $percentage, $minimumPercent);

if ($percentage < $minimumPercent) {
    fwrite(STDERR, "Coverage is below the required threshold.\n");
    exit(1);
}
