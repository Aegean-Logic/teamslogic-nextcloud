<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aegean Logic
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\TeamsLogic\Remote;

use OCP\Http\Client\IClientService;
use Throwable;

/**
 * JSON over HTTPS with a bearer token, for the Google and Graph APIs.
 *
 * 412 throws PreconditionFailedException, 404 and 410 come back as a status for the caller to
 * judge, everything else outside 2xx throws RemoteException.
 */
class Http {
	public function __construct(
		private IClientService $clientService,
		private string $token,
	) {
	}

	/**
	 * @param array<string, mixed>|null $json
	 * @param array<string, string> $headers
	 * @return array{status: int, json: ?array, headers: array<string, string>}
	 */
	public function request(string $method, string $url, ?array $json = null, array $headers = []): array {
		$options = [
			'headers' => $headers + ['Authorization' => 'Bearer ' . $this->token, 'Accept' => 'application/json'],
			'http_errors' => false,
			'timeout' => 60,
		];
		if ($json !== null) {
			$options['body'] = json_encode($json, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
			$options['headers']['Content-Type'] = 'application/json';
		}
		[$status, $body, $responseHeaders] = $this->send($method, $url, $options);
		$data = $body === '' ? null : json_decode($body, true);
		$this->check($method, $url, $status, $body, is_array($data) ? $data : null);
		return ['status' => $status, 'json' => is_array($data) ? $data : null, 'headers' => $responseHeaders];
	}

	/** @return array{status: int, body: string, type: string} raw bytes (a photo) */
	public function getRaw(string $url): array {
		[$status, $body, $headers] = $this->send('GET', $url, [
			'headers' => ['Authorization' => 'Bearer ' . $this->token],
			'http_errors' => false,
			'timeout' => 60,
		]);
		if ($status !== 404) {
			$this->check('GET', $url, $status, '', null);
		}
		return ['status' => $status, 'body' => $body, 'type' => $headers['content-type'] ?? ''];
	}

	public function putRaw(string $url, string $bytes, string $type): void {
		[$status, $body] = $this->send('PUT', $url, [
			'headers' => ['Authorization' => 'Bearer ' . $this->token, 'Content-Type' => $type],
			'body' => $bytes,
			'http_errors' => false,
			'timeout' => 60,
		]);
		$this->check('PUT', $url, $status, $body, null);
	}

	/** @return array{0: int, 1: string, 2: array<string, string>} */
	private function send(string $method, string $url, array $options): array {
		try {
			$client = $this->clientService->newClient();
			$response = match ($method) {
				'GET' => $client->get($url, $options),
				'POST' => $client->post($url, $options),
				'PUT' => $client->put($url, $options),
				'PATCH' => $client->patch($url, $options),
				'DELETE' => $client->delete($url, $options),
				default => throw new RemoteException("Unsupported method $method"),
			};
		} catch (RemoteException $e) {
			throw $e;
		} catch (Throwable $e) {
			throw new RemoteException("$method " . self::short($url) . ' failed: ' . $e->getMessage(), 0, '', $e);
		}
		$headers = [];
		foreach ((array)$response->getHeaders() as $name => $values) {
			$headers[strtolower((string)$name)] = is_array($values) ? implode(', ', $values) : (string)$values;
		}
		return [(int)$response->getStatusCode(), (string)$response->getBody(), $headers];
	}

	private function check(string $method, string $url, int $status, string $body, ?array $data): void {
		if (($status >= 200 && $status < 300) || $status === 404 || $status === 410) {
			return;
		}
		$message = (string)($data['error']['message'] ?? $data['error_description'] ?? '');
		$reason = (string)($data['error']['status'] ?? $data['error']['code'] ?? '');
		$text = "$method " . self::short($url) . " → $status" . ($message !== '' ? ": $message" : '');
		if ($status === 412 || ($status === 400 && $reason === 'FAILED_PRECONDITION' && stripos($message, 'etag') !== false)) {
			throw new PreconditionFailedException($text, $status, $body);
		}
		if ($status === 400 && stripos($message . $reason . $body, 'EXPIRED_SYNC_TOKEN') !== false) {
			throw new SyncTokenExpiredException($text, $status, $body);
		}
		throw new RemoteException($text, $status, $body);
	}

	/** A URL without its query, for logs (delta links carry state tokens). */
	public static function short(string $url): string {
		return strtok($url, '?') ?: $url;
	}
}
