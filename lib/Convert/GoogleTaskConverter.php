<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aegean Logic
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\TeamsLogic\Convert;

use Sabre\VObject\Component\VCalendar;

/**
 * VTODO ⇄ Google Tasks task.
 *
 * Google Tasks has a title, notes, a due day (the time is ignored), done or not, and a parent
 * (subtasks). The parent comes across as RELATED-TO; a new local subtask is made a top-level task.
 */
final class GoogleTaskConverter {
	/**
	 * @param array<string, mixed> $task
	 * @param string|null $parentUid the local UID of the parent task, for a subtask
	 */
	public function toCalendar(array $task, string $uid, ?string $parentUid = null): VCalendar {
		$cal = Tasks::newCalendar();
		$todo = $cal->add('VTODO', ['UID' => $uid]);
		Tasks::stamp($todo, $task['updated'] ?? null, null);
		Tasks::setText($todo, 'SUMMARY', (string)($task['title'] ?? ''));
		Tasks::setText($todo, 'DESCRIPTION', isset($task['notes']) ? (string)$task['notes'] : null);
		Tasks::setDay($todo, 'DUE', isset($task['due']) ? (string)$task['due'] : null);
		$done = ($task['status'] ?? 'needsAction') === 'completed';
		$todo->STATUS = $done ? 'COMPLETED' : 'NEEDS-ACTION';
		if ($done) {
			Tasks::setCompleted($todo, true, isset($task['completed']) ? (string)$task['completed'] : ($task['updated'] ?? null));
		}
		if ($parentUid !== null) {
			$todo->add('RELATED-TO', $parentUid, ['RELTYPE' => 'PARENT']);
		}
		return $cal;
	}

	/**
	 * The Google body for a VTODO.
	 *
	 * @return array<string, mixed>
	 */
	public function fromCalendar(VCalendar $cal): array {
		$todo = Tasks::todoOf($cal) ?? throw new UnsupportedException('No task in the calendar object');
		$due = Tasks::day($todo, 'DUE');
		$done = Tasks::isCompleted($todo);
		$body = [
			'title' => Tasks::text($todo, 'SUMMARY') ?? '',
			'notes' => Tasks::text($todo, 'DESCRIPTION') ?? '',
			// Google keeps the day only; it wants it as midnight UTC.
			'due' => $due !== null ? $due . 'T00:00:00.000Z' : null,
			'status' => $done ? 'completed' : 'needsAction',
		];
		if ($done) {
			$completed = Tasks::instant($todo, 'COMPLETED');
			if ($completed !== null) {
				$body['completed'] = $completed->format('Y-m-d\TH:i:s.000\Z');
			}
		} else {
			$body['completed'] = null;
		}
		return $body;
	}
}
