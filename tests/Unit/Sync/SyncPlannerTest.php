<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aegean Logic
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\TeamsLogic\Tests\Unit\Sync;

use OCA\TeamsLogic\Sync\SyncAction;
use OCA\TeamsLogic\Sync\SyncPlanner;
use PHPUnit\Framework\TestCase;

class SyncPlannerTest extends TestCase {
	private SyncPlanner $planner;

	/** What the last round left: three items in step on both sides. */
	private const KNOWN = [
		'r1' => ['localUri' => 'a.ics', 'remoteEtag' => 'R1', 'localEtag' => 'L1'],
		'r2' => ['localUri' => 'b.ics', 'remoteEtag' => 'R2', 'localEtag' => 'L2'],
		'r3' => ['localUri' => 'c.ics', 'remoteEtag' => 'R3', 'localEtag' => 'L3'],
	];

	protected function setUp(): void {
		$this->planner = new SyncPlanner();
	}

	/** @return list<string> "type remoteId localUri[ conflict]" */
	private function summary(array $actions): array {
		return array_map(
			static fn (SyncAction $a) => trim(sprintf('%s %s %s%s', $a->type, $a->remoteId ?? '-', $a->localUri ?? '-', $a->conflict ? ' conflict' : '')),
			$actions,
		);
	}

	public function testNothingChanged(): void {
		$this->assertSame([], $this->planner->plan([], false, [], self::KNOWN, false));
	}

	public function testRemoteChangesComeIn(): void {
		$actions = $this->planner->plan([
			'r1' => ['etag' => 'R1b', 'deleted' => false],
			'r2' => ['etag' => null, 'deleted' => true],
			'r9' => ['etag' => 'R9', 'deleted' => false],
			'r8' => ['etag' => null, 'deleted' => true],
		], false, [], self::KNOWN, false);
		$this->assertSame(['pull-update r1 a.ics', 'pull-delete r2 b.ics', 'pull-create r9 -'], $this->summary($actions));
	}

	public function testLocalChangesGoOut(): void {
		$actions = $this->planner->plan([], false, [
			'a.ics' => 'L1b',
			'b.ics' => null,
			'new.ics' => 'N1',
			'gone-before-sync.ics' => null,
		], self::KNOWN, false);
		$this->assertSame(['push-update r1 a.ics', 'push-delete r2 b.ics', 'push-create - new.ics'], $this->summary($actions));
	}

	public function testOurOwnWritesAreNotPushedBack(): void {
		// The local token reports a.ics changed, but its ETag is the one we wrote last round.
		$actions = $this->planner->plan(['r1' => ['etag' => 'R1', 'deleted' => false]], true, ['a.ics' => 'L1'], self::KNOWN + [], false);
		$this->assertSame(['pull-delete r2 b.ics', 'pull-delete r3 c.ics'], $this->summary($actions));
	}

	public function testChangedOnBothSidesRemoteWins(): void {
		$actions = $this->planner->plan([
			'r1' => ['etag' => 'R1b', 'deleted' => false],
			'r2' => ['etag' => null, 'deleted' => true],
			'r3' => ['etag' => 'R3b', 'deleted' => false],
		], false, [
			'a.ics' => 'L1b',
			'b.ics' => 'L2b',
			'c.ics' => null,
		], self::KNOWN, false);
		$this->assertSame([
			'pull-update r1 a.ics conflict',
			'pull-delete r2 b.ics conflict',
			'pull-create r3 c.ics conflict',
		], $this->summary($actions));
	}

	public function testDeletedOnBothSidesIsForgotten(): void {
		$actions = $this->planner->plan(['r2' => ['etag' => null, 'deleted' => true]], false, ['b.ics' => null], self::KNOWN, false);
		$this->assertSame(['forget r2 b.ics'], $this->summary($actions));
	}

	public function testFullListingFindsRemoteDeletionsAndSkipsUnchanged(): void {
		$actions = $this->planner->plan([
			'r1' => ['etag' => 'R1', 'deleted' => false],
			'r2' => ['etag' => 'R2b', 'deleted' => false],
			'r4' => ['etag' => 'R4', 'deleted' => false],
		], true, ['a.ics' => 'L1b'], self::KNOWN, false);
		// r1 unchanged remotely, changed locally: pushed. r3 missing from the listing: deleted.
		$this->assertSame([
			'pull-update r2 b.ics',
			'pull-create r4 -',
			'pull-delete r3 c.ics',
			'push-update r1 a.ics',
		], $this->summary($actions));
	}

	public function testReadOnlyCollectionUndoesLocalEdits(): void {
		$actions = $this->planner->plan([], false, [
			'a.ics' => 'L1b',
			'b.ics' => null,
			'mine.ics' => 'M1',
		], self::KNOWN, true);
		$this->assertSame(['pull-update r1 a.ics', 'pull-create r2 b.ics'], $this->summary($actions));
		$this->assertSame('read-only: local edit undone', $actions[0]->note);
	}

	public function testPullsComeBeforePushes(): void {
		$actions = $this->planner->plan(['r9' => ['etag' => 'R9', 'deleted' => false]], false, ['new.ics' => 'N1', 'a.ics' => 'L1b'], self::KNOWN, false);
		$this->assertSame(['pull-create r9 -', 'push-create - new.ics', 'push-update r1 a.ics'], $this->summary($actions));
		$this->assertTrue($actions[0]->isPull());
		$this->assertTrue($actions[1]->isPush());
	}
}
