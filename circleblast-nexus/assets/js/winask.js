/**
 * CircleBlast Nexus – Quick "Share Win / Ask" modal.
 *
 * A lightweight header action that lets any member share a win or an ask
 * with the club in one field. Submits via the existing journal AJAX
 * endpoint (auto-shared to members, dated today) so the entry lands on the
 * Club "Asks & Wins" board for the monthly meeting.
 *
 * Depends on window.cbnexusJournal (localized on the journal script) for the
 * AJAX URL and nonce.
 */
(function () {
	'use strict';

	var overlay, modal, form, msgEl, submitBtn, contentLabel;
	var closing = false;

	var LABELS = {
		win: 'What did you win?',
		ask: 'What do you need help with?'
	};
	var PLACEHOLDERS = {
		win: 'e.g. Closed a new client this week!',
		ask: 'e.g. Looking for an intro to a commercial realtor.'
	};

	function cfg() { return window.cbnexusJournal || {}; }

	function init() {
		overlay      = document.getElementById('cbnexus-winask-overlay');
		modal        = document.getElementById('cbnexus-winask-modal');
		form         = document.getElementById('cbnexus-winask-form');
		msgEl        = document.getElementById('cbnexus-winask-msg');
		contentLabel = document.getElementById('cbnexus-winask-content-label');

		if (!overlay || !modal || !form) return;

		submitBtn = form.querySelector('button[type="submit"]');

		// Open triggers.
		document.addEventListener('click', function (e) {
			var trigger = e.target.closest('[data-winask-open]');
			if (!trigger) return;
			e.preventDefault();
			openModal(trigger);
		});

		// Close triggers.
		overlay.addEventListener('click', closeModal);
		var closeBtns = modal.querySelectorAll('[data-winask-close]');
		for (var i = 0; i < closeBtns.length; i++) {
			closeBtns[i].addEventListener('click', closeModal);
		}
		document.addEventListener('keydown', function (e) {
			if (e.key === 'Escape' && overlay.classList.contains('open')) {
				closeModal();
			}
		});

		// Update label/placeholder when the type changes.
		var radios = form.querySelectorAll('input[name="entry_type"]');
		for (var r = 0; r < radios.length; r++) {
			radios[r].addEventListener('change', function () { applyType(this.value); });
		}

		form.addEventListener('submit', handleSubmit);
	}

	function currentType() {
		var checked = form.querySelector('input[name="entry_type"]:checked');
		return checked ? checked.value : 'win';
	}

	function applyType(type) {
		var content = form.querySelector('[name="content"]');
		if (contentLabel) {
			contentLabel.innerHTML = (LABELS[type] || LABELS.win) + ' <span class="cbnexus-required">*</span>';
		}
		if (content) { content.placeholder = PLACEHOLDERS[type] || PLACEHOLDERS.win; }
	}

	function openModal(trigger) {
		form.reset();
		msgEl.style.display = 'none';
		msgEl.className = 'cbnexus-winask-msg';
		if (submitBtn) {
			submitBtn.disabled = false;
			submitBtn.textContent = 'Share with Club';
		}
		// A trigger may request a starting type, e.g. data-winask-type="ask".
		var startType = (trigger && trigger.getAttribute('data-winask-type')) || 'win';
		var radio = form.querySelector('input[name="entry_type"][value="' + startType + '"]');
		if (radio) { radio.checked = true; }
		applyType(currentType());

		overlay.classList.add('open');
		modal.classList.add('open');
		var content = form.querySelector('[name="content"]');
		if (content) setTimeout(function () { content.focus(); }, 150);
	}

	function closeModal() {
		if (closing) return;
		closing = true;
		overlay.classList.remove('open');
		modal.classList.remove('open');
		setTimeout(function () { closing = false; }, 300);
	}

	function handleSubmit(e) {
		e.preventDefault();

		var content = form.querySelector('[name="content"]').value.trim();
		if (!content) {
			showMsg('Please write something to share.', 'error');
			return;
		}

		if (submitBtn) {
			submitBtn.disabled = true;
			submitBtn.textContent = 'Sharing…';
		}

		var data = new FormData();
		data.append('action', 'cbnexus_journal_add');
		data.append('nonce', cfg().nonce);
		data.append('entry_type', currentType());
		data.append('content', content);
		data.append('visibility', 'members'); // auto-share with the club
		// entry_date omitted → server defaults to today.

		var xhr = new XMLHttpRequest();
		xhr.open('POST', cfg().ajax_url, true);
		xhr.onload = function () {
			var resp;
			try { resp = JSON.parse(xhr.responseText); } catch (ex) {
				fail();
				return;
			}
			if (resp && resp.success) {
				var isAsk = currentType() === 'ask';
				showMsg(isAsk ? 'Shared! The group will see your ask. 🙌' : 'Nice win! Shared with the club. 🎉', 'success');
				form.reset();
				if (submitBtn) { submitBtn.textContent = 'Shared ✓'; }
				setTimeout(closeModal, 1600);
			} else {
				var err = (resp && resp.data && resp.data.errors && resp.data.errors[0]) || 'Something went wrong.';
				showMsg(err, 'error');
				reset();
			}
		};
		xhr.onerror = fail;
		xhr.send(data);

		function fail() { showMsg('Network error. Please try again.', 'error'); reset(); }
		function reset() { if (submitBtn) { submitBtn.disabled = false; submitBtn.textContent = 'Share with Club'; } }
	}

	function showMsg(text, type) {
		if (!msgEl) return;
		msgEl.textContent = text;
		msgEl.className = 'cbnexus-winask-msg cbnexus-winask-msg-' + type;
		msgEl.style.display = '';
	}

	if (document.readyState === 'loading') {
		document.addEventListener('DOMContentLoaded', init);
	} else {
		init();
	}
})();
