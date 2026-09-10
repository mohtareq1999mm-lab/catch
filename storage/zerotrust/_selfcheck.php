<?php

// =====================================================================
// PHASE 0 — HARNESS SELF-VALIDATION (controlled failures)
// Two deliberate harness defects are simulated. The harness MUST record
// them as FAIL (honest reporting), then after "revert" they MUST PASS.
// No application code is touched.
// =====================================================================
ev('=================================================================');
ev('PHASE 0 — HARNESS SELF-VALIDATION');

// ---- DEFECT 1: stale-state comparator (mirrors the auth-guard/closure
//      capture bug class we actually hit earlier: a closure returning a
//      PRE-increment value while the harness expected the post value). ----
$evCount = 0;
$staleCounter = function () use (&$evCount) {
    $c = $evCount;
    $evCount++;
    return $c;
};
record('TC-SELF-CTRL-1', $staleCounter() === 1, 'CONTROLLED FAIL #1 — stale counter returned 0 instead of 1 (must be recorded FAIL)');

// ---- DEFECT 2: route classifier that mislabels POST as read (mirrors
//      the read/write URI-collision bug class we actually hit). ----
$isReadBroken = function ($method) {
    return true;
};
record('TC-SELF-CTRL-2', $isReadBroken('POST') === false, 'CONTROLLED FAIL #2 — POST misclassified as read (must be recorded FAIL)');

// ---- Detection proof: the harness must have honestly recorded both as FAIL ----
global $results;
$detected = ($results['TC-SELF-CTRL-1'] === false) && ($results['TC-SELF-CTRL-2'] === false);
record('TC-SELF-DETECT', $detected, 'harness recorded both controlled failures as FAIL (no false PASS)');

// ---- REVERT both defects ----
$evCount = 0;
$goodCounter = function () use (&$evCount) {
    return ++$evCount;
};
$isReadGood = fn ($m) => $m === 'GET';
record('TC-SELF-CTRL-1-FIX', $goodCounter() === 1, 'reverted defect 1 — correct counter returns 1 (must be PASS)');
record('TC-SELF-CTRL-2-FIX', $isReadGood('POST') === false && $isReadGood('GET') === true, 'reverted defect 2 — POST write / GET read classified correctly (must be PASS)');