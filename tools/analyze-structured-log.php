#!/usr/bin/env php
<?php
declare(strict_types=1);

$shortopts = '';
$longopts = [
    'input:',
    'output::',
    'format::',
];

$options = getopt($shortopts, $longopts);

if ($options === false || !isset($options['input'])) {
    fwrite(STDERR, "Usage: analyze-structured-log.php --input=/path/to/structured.log [--output=summary.json] [--format=json|table]\n");
    exit(1);
}

$input = (string) $options['input'];
if (!is_file($input)) {
    fwrite(STDERR, "Input file '{$input}' not found.\n");
    exit(1);
}

$format = isset($options['format']) ? strtolower((string) $options['format']) : 'json';
if (!in_array($format, ['json', 'table'], true)) {
    fwrite(STDERR, "Unsupported format '{$format}'. Use json or table.\n");
    exit(1);
}

$handle = @fopen($input, 'r');
if (!is_resource($handle)) {
    fwrite(STDERR, "Unable to open '{$input}' for reading.\n");
    exit(1);
}

$summary = [
    'generated_at' => date(DATE_ATOM),
    'host' => php_uname('n'),
    'source' => realpath($input) ?: $input,
    'total_events' => 0,
    'tasks' => [],
];

while (($line = fgets($handle)) !== false) {
    $line = trim($line);
    if ($line === '') {
        continue;
    }

    $decoded = json_decode($line, true);
    if (!is_array($decoded)) {
        continue;
    }

    $summary['total_events']++;

    $event = strtolower((string) ($decoded['context']['event'] ?? $decoded['event'] ?? ''));
    $taskName = $decoded['context']['task'] ?? null;

    if ($event !== 'finish' || !is_string($taskName) || $taskName === '') {
        continue;
    }

    $taskKey = $taskName;
    if (!isset($summary['tasks'][$taskKey])) {
        $summary['tasks'][$taskKey] = [
            'name' => $taskName,
            'count' => 0,
            'success_count' => 0,
            'failure_count' => 0,
            'total_duration_seconds' => 0.0,
        ];
    }

    $entry =& $summary['tasks'][$taskKey];
    $entry['count']++;

    $exitCode = $decoded['context']['exit_code'] ?? $decoded['exit_code'] ?? 0;
    $exitCode = is_numeric($exitCode) ? (int) $exitCode : 0;
    if ($exitCode === 0) {
        $entry['success_count']++;
    } else {
        $entry['failure_count']++;
    }

    $duration = $decoded['context']['duration_seconds'] ?? $decoded['duration_seconds'] ?? 0;
    if (is_numeric($duration)) {
        $entry['total_duration_seconds'] += (float) $duration;
    }
}

fclose($handle);

// Compute averages and flatten for output
foreach ($summary['tasks'] as &$taskSummary) {
    $count = max(1, $taskSummary['count']);
    $taskSummary['average_duration_seconds'] = $taskSummary['total_duration_seconds'] / $count;
}
unset($taskSummary);

$outputPath = $options['output'] ?? null;

if ($format === 'json') {
    $json = json_encode($summary, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    if ($outputPath !== null) {
        if (@file_put_contents($outputPath, $json . PHP_EOL) === false) {
            fwrite(STDERR, "Unable to write output to '{$outputPath}'.\n");
            exit(1);
        }
    } else {
        fwrite(STDOUT, $json . PHP_EOL);
    }
    exit(0);
}

// Table format output
$rows = [];
$header = sprintf("%-30s %8s %8s %8s %12s\n", 'Task', 'Runs', 'OK', 'Fail', 'Avg (s)');
$rows[] = $header;
$rows[] = str_repeat('-', strlen($header) - 1) . "\n";
foreach ($summary['tasks'] as $taskSummary) {
    $rows[] = sprintf(
        "%-30s %8d %8d %8d %12.3f\n",
        $taskSummary['name'],
        $taskSummary['count'],
        $taskSummary['success_count'],
        $taskSummary['failure_count'],
        $taskSummary['average_duration_seconds']
    );
}
$output = implode('', $rows);
if ($outputPath !== null) {
    if (@file_put_contents($outputPath, $output) === false) {
        fwrite(STDERR, "Unable to write output to '{$outputPath}'.\n");
        exit(1);
    }
} else {
    fwrite(STDOUT, $output);
}

exit(0);
