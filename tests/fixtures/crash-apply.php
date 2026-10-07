<?php
/*
 * Runs one apply and SIGKILLs itself at a named step.
 *
 * 🚨 A real SIGKILL, not a thrown exception. An exception unwinds the stack and
 * runs destructors and finally blocks; a host killing a request does not. The
 * whole claim being tested is "an interrupted apply is recoverable", and it is
 * only worth anything if the interruption is the real thing.
 *
 * argv: <vendorDir> <stagingDir> <trashDir> <journalPath> <changesJson> <killAt> [killOnPackage]
 * killAt is a step label — journalled | stashed | installed | completed — or
 * "never" to run to completion.
 */
require __DIR__ . '/../../vendor/autoload.php';

use ErnestDefoe\Millwright\Apply\Applier;
use ErnestDefoe\Millwright\Apply\Journal;
use ErnestDefoe\Millwright\Plan\Change;

[$script, $vendor, $staging, $trash, $journalPath, $changesJson, $killAt] = $argv;
$killOnPackage = $argv[7] ?? '';

$changes = array_map(
    fn (array $row) => Change::fromArray($row),
    json_decode($changesJson, true, 512, JSON_THROW_ON_ERROR)
);

$journal = new Journal($journalPath);

$applier = new Applier($vendor, $staging, $trash, $journal, function (string $label, Change $change) use ($killAt, $killOnPackage) {
    if ($label === $killAt && ($killOnPackage === '' || $change->package === $killOnPackage)) {
        // No shutdown functions, no flush, no unwinding — exactly what a host
        // killing an overrunning request does.
        posix_kill(posix_getpid(), SIGKILL);
        usleep(500000);   // unreachable in practice; guards against a missed signal
    }
});

$applier->apply($changes);

echo "completed\n";
