<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aegean Logic
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\TeamsLogic\Convert;

use DateTimeImmutable;
use DateTimeZone;
use Sabre\VObject\Component\VCalendar;

/**
 * VTODO ⇄ Microsoft To Do task (Graph todoTask).
 *
 * Title, notes, due and start days, status, importance (PRIORITY 1 / 5 / 9), categories and a
 * reminder. To Do's "waiting on others" and "deferred" have no iCalendar status: they're kept in
 * X-TEAMSLOGIC-TODO-STATUS and read as not done. Steps (checklist items) and recurrence stay in To Do.
 */
final class GraphTaskConverter {
	public const STATUS = 'X-TEAMSLOGIC-TODO-STATUS';

	private const STATUS_IN = ['notStarted' => 'NEEDS-ACTION', 'inProgress' => 'IN-PROCESS', 'completed' => 'COMPLETED',
		'waitingOnOthers' => 'NEEDS-ACTION', 'deferred' => 'NEEDS-ACTION'];
	private const PRIORITY_IN = ['high' => 1, 'normal' => 5, 'low' => 9];

	/** @param array<string, mixed> $task */
	public function toCalendar(array $task, string $uid): VCalendar {
		$cal = Tasks::newCalendar();
		$todo = $cal->add('VTODO', ['UID' => $uid]);
		Tasks::stamp($todo, $task['lastModifiedDateTime'] ?? null, $task['createdDateTime'] ?? null);
		Tasks::setText($todo, 'SUMMARY', (string)($task['title'] ?? ''));
		$body = (array)($task['body'] ?? []);
		$notes = (string)($body['content'] ?? '');
		if (strtolower((string)($body['contentType'] ?? 'text')) === 'html') {
			$notes = ICal::htmlToText($notes);
		}
		Tasks::setText($todo, 'DESCRIPTION', $notes);
		Tasks::setDay($todo, 'DUE', $task['dueDateTime']['dateTime'] ?? null);
		Tasks::setDay($todo, 'DTSTART', $task['startDateTime']['dateTime'] ?? null);

		$status = (string)($task['status'] ?? 'notStarted');
		$todo->STATUS = self::STATUS_IN[$status] ?? 'NEEDS-ACTION';
		if ($status === 'waitingOnOthers' || $status === 'deferred') {
			$todo->{self::STATUS} = $status;
		}
		if ($status === 'completed') {
			Tasks::setCompleted($todo, true, self::utc($task['completedDateTime'] ?? null) ?? ($task['lastModifiedDateTime'] ?? null));
		}
		$todo->PRIORITY = self::PRIORITY_IN[(string)($task['importance'] ?? 'normal')] ?? 5;
		$categories = array_values(array_filter(array_map('strval', (array)($task['categories'] ?? []))));
		if ($categories !== []) {
			$todo->add('CATEGORIES', $categories);
		}
		if (!empty($task['isReminderOn']) && ($reminder = self::utc($task['reminderDateTime'] ?? null)) !== null) {
			Tasks::setReminder($todo, new DateTimeImmutable($reminder));
		}
		return $cal;
	}

	/**
	 * The Graph body for a VTODO.
	 *
	 * @return array<string, mixed>
	 */
	public function fromCalendar(VCalendar $cal): array {
		$todo = Tasks::todoOf($cal) ?? throw new UnsupportedException('No task in the calendar object');
		$priority = (int)(Tasks::text($todo, 'PRIORITY') ?? 0);
		$done = Tasks::isCompleted($todo);
		$status = match (true) {
			$done => 'completed',
			strtoupper(Tasks::text($todo, 'STATUS') ?? '') === 'IN-PROCESS' => 'inProgress',
			default => in_array(Tasks::text($todo, self::STATUS), ['waitingOnOthers', 'deferred'], true) ? (string)Tasks::text($todo, self::STATUS) : 'notStarted',
		};
		$body = [
			'title' => Tasks::text($todo, 'SUMMARY') ?? '',
			'body' => ['contentType' => 'text', 'content' => Tasks::text($todo, 'DESCRIPTION') ?? ''],
			'status' => $status,
			'importance' => $priority >= 1 && $priority <= 4 ? 'high' : ($priority >= 6 ? 'low' : 'normal'),
			'dueDateTime' => self::day(Tasks::day($todo, 'DUE')),
			'startDateTime' => self::day(Tasks::day($todo, 'DTSTART')),
			'categories' => isset($todo->CATEGORIES) ? array_values(array_map('strval', $todo->CATEGORIES->getParts())) : [],
		];
		if ($done && ($completed = Tasks::instant($todo, 'COMPLETED')) !== null) {
			$body['completedDateTime'] = ['dateTime' => $completed->format('Y-m-d\TH:i:s'), 'timeZone' => 'UTC'];
		}
		$reminder = Tasks::reminder($todo);
		$body['isReminderOn'] = $reminder !== null;
		if ($reminder !== null) {
			$body['reminderDateTime'] = ['dateTime' => $reminder->format('Y-m-d\TH:i:s'), 'timeZone' => 'UTC'];
		}
		return $body;
	}

	/** @return array{dateTime: string, timeZone: string}|null a whole day, as To Do writes it */
	private static function day(?string $day): ?array {
		return $day === null ? null : ['dateTime' => $day . 'T00:00:00', 'timeZone' => 'UTC'];
	}

	/** Graph's {dateTime, timeZone} as an ISO instant. */
	private static function utc(mixed $value): ?string {
		if (!is_array($value) || empty($value['dateTime'])) {
			return null;
		}
		$raw = preg_replace('/(\.\d{6})\d+/', '$1', (string)$value['dateTime']) ?? (string)$value['dateTime'];
		return (new DateTimeImmutable($raw, TimeZones::toIana($value['timeZone'] ?? 'UTC')))->setTimezone(new DateTimeZone('UTC'))->format(DATE_ATOM);
	}
}
