/**
 * SPDX-FileCopyrightText: 2026 Aegean Logic
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * Admin settings: saves the form. Plain script, no build step.
 */
(function () {
	'use strict'

	function requestToken() {
		return (window.OC && window.OC.requestToken) || document.head.dataset.requesttoken || ''
	}

	function init() {
		const root = document.getElementById('teamslogic-admin')
		const form = document.getElementById('teamslogic-admin-form')
		const status = document.getElementById('teamslogic-admin-status')
		if (!root || !form) {
			return
		}

		root.querySelectorAll('.teamslogic-copy').forEach((input) => {
			input.addEventListener('focus', () => input.select())
		})

		form.addEventListener('submit', async (event) => {
			event.preventDefault()
			const data = {}
			form.querySelectorAll('input[name]').forEach((input) => {
				if (input.type === 'checkbox') {
					data[input.name] = input.checked
				} else if (input.type === 'password') {
					// An empty secret keeps the saved one.
					if (input.value !== '') {
						data[input.name] = input.value
					}
				} else {
					data[input.name] = input.value
				}
			})
			status.textContent = t('teamslogic', 'Saving…')
			try {
				const response = await fetch(root.dataset.saveUrl, {
					method: 'POST',
					headers: {
						'Content-Type': 'application/json',
						Accept: 'application/json',
						requesttoken: requestToken(),
					},
					body: JSON.stringify(data),
				})
				if (!response.ok) {
					throw new Error(response.status + ' ' + response.statusText)
				}
				const saved = await response.json()
				form.querySelectorAll('input[type=password]').forEach((input) => {
					input.value = ''
				})
				document.getElementById('teamslogic-google-secret').placeholder = saved.googleSecretSet ? t('teamslogic', 'Saved (leave empty to keep)') : ''
				document.getElementById('teamslogic-ms-secret').placeholder = saved.microsoftSecretSet ? t('teamslogic', 'Saved (leave empty to keep)') : ''
				status.textContent = saved.mailConfigured
					? t('teamslogic', 'Saved. Nextcloud Mail uses the same clients.')
					: t('teamslogic', 'Saved.')
			} catch (e) {
				status.textContent = t('teamslogic', 'Could not save: {error}', { error: e.message })
			}
		})
	}

	// t() comes from Nextcloud's page; fall back to the English text.
	const t = window.t || ((app, text, vars) => text.replace(/{(\w+)}/g, (m, k) => (vars && k in vars ? vars[k] : m)))

	if (document.readyState === 'loading') {
		document.addEventListener('DOMContentLoaded', init)
	} else {
		init()
	}
})()
