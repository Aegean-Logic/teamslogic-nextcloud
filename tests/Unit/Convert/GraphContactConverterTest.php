<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aegean Logic
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\TeamsLogic\Tests\Unit\Convert;

use OCA\TeamsLogic\Convert\GraphContactConverter;
use OCA\TeamsLogic\Convert\VCards;
use OCA\TeamsLogic\Tests\Fixtures;
use PHPUnit\Framework\TestCase;

class GraphContactConverterTest extends TestCase {
	private const PNG = "\x89PNG\r\n\x1a\n0000";

	private GraphContactConverter $converter;

	protected function setUp(): void {
		$this->converter = new GraphContactConverter();
	}

	public function testContactToVCard(): void {
		$card = $this->converter->toVCard(Fixtures::json('graph/contact.json'), 'graph-uid-1', self::PNG);

		$this->assertSame('Jane Public', (string)$card->FN);
		$this->assertSame(['Public', 'Jane', 'Quinn', 'Dr', 'PhD'], $card->N->getParts());
		$this->assertSame('JQ', (string)$card->NICKNAME);
		$emails = $card->select('EMAIL');
		$this->assertSame(['j.public@contoso.com', 'jane@example.com'], array_map('strval', $emails));
		$this->assertContains('PREF', VCards::types($emails[0]));

		$tels = [];
		foreach ($card->select('TEL') as $tel) {
			$tels[(string)$tel] = VCards::types($tel);
		}
		$this->assertSame([
			'+44 7700 900123' => ['CELL'],
			'+44 20 7946 0123' => ['WORK', 'VOICE'],
			'+44 20 7946 0555' => ['HOME', 'VOICE'],
		], $tels);

		$addresses = VCards::addresses($card);
		$this->assertCount(2, $addresses, 'the empty "other" address is skipped');
		$this->assertSame(['', '', '1 High Street', 'London', '', 'SW1A 1AA', 'United Kingdom'], $addresses[0]['parts']);
		$this->assertSame(['WORK'], $addresses[1]['types']);

		$this->assertSame(['Contoso Ltd', 'Research'], $card->ORG->getParts());
		$this->assertSame('Head of Research', (string)$card->TITLE);
		$this->assertSame('1984-03-09', (string)$card->BDAY);
		$this->assertSame('https://contoso.com/jane', (string)$card->URL);
		$this->assertSame('Prefers email.', (string)$card->NOTE);
		$this->assertSame('Suppliers', (string)$card->CATEGORIES);
		$this->assertSame(['data' => self::PNG, 'mime' => 'image/png'], VCards::photo($card));

		// The card survives being written and read as Nextcloud stores it.
		$reread = VCards::parse($card->serialize());
		$this->assertSame(['data' => self::PNG, 'mime' => 'image/png'], VCards::photo($reread));
	}

	public function testContactRoundTrip(): void {
		$original = Fixtures::json('graph/contact.json');
		$body = $this->converter->toContact($this->converter->toVCard($original, 'x'));

		foreach (['givenName', 'surname', 'middleName', 'title', 'generation', 'displayName', 'nickName', 'mobilePhone',
			'businessPhones', 'homePhones', 'companyName', 'department', 'jobTitle', 'businessHomePage', 'personalNotes', 'categories'] as $key) {
			$this->assertSame($original[$key], $body[$key], $key);
		}
		$this->assertSame(['j.public@contoso.com', 'jane@example.com'], array_column($body['emailAddresses'], 'address'));
		$this->assertEquals($original['homeAddress'], $body['homeAddress']);
		$this->assertEquals($original['businessAddress'], $body['businessAddress']);
		$this->assertInstanceOf(\stdClass::class, $body['otherAddress']);
		$this->assertSame('1984-03-09T11:59:00Z', $body['birthday']);
	}

	public function testLocalVCardToContact(): void {
		$card = VCards::parse(Fixtures::text('local/contact.vcf'));
		$body = $this->converter->toContact($card);

		$this->assertSame("Sam O'Neill", $body['displayName']);
		$this->assertSame('Mr', $body['title']);
		// The preferred address first, and Outlook's three slots at most.
		$this->assertSame(['sam.oneill@example.net', 'sam@fabrikam.com', 'sam@old.example.net'], array_column($body['emailAddresses'], 'address'));
		$this->assertSame('+44 7700 900456', $body['mobilePhone']);
		$this->assertSame(['+44 117 496 0123'], $body['businessPhones']);
		$this->assertSame(['+44 117 496 0999'], $body['homePhones']);
		$this->assertSame(['street' => "Harbour Road\nUnit 4", 'city' => 'Bristol', 'state' => '', 'postalCode' => 'BS1 5TT', 'countryOrRegion' => 'United Kingdom'], $body['businessAddress']);
		$this->assertSame('1604-08-30T11:59:00Z', $body['birthday']);
		$this->assertSame(['Customers'], $body['categories']);
		$this->assertSame('image/png', $this->converter->photoFor($card)['mime']);

		// Back from Outlook, the 1604 birthday is yearless again.
		$card = $this->converter->toVCard($body + ['id' => 'x'], 'x');
		$this->assertSame([null, 8, 30], VCards::birthday($card));
	}
}
