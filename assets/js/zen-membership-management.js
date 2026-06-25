(function () {
	var activeTrigger = null;
	var activeModal = null;
	var activeModalParent = null;
	var activeModalNextSibling = null;

	function getFocusableElement(modal) {
		return modal.querySelector('.zmm-cancel-modal__confirm, .zmm-cancel-modal__keep, a[href], button:not([disabled])');
	}

	function openModal(trigger) {
		var section = trigger.closest('.zmm-panel--cancel-membership');
		var modal = section ? section.querySelector('[data-zmm-cancel-modal]') : null;

		if (!modal) {
			return;
		}

		activeTrigger = trigger;
		activeModal = modal;
		activeModalParent = modal.parentNode;
		activeModalNextSibling = modal.nextSibling;
		document.body.appendChild(modal);
		modal.hidden = false;
		document.body.classList.add('zmm-cancel-modal-open');

		var focusTarget = getFocusableElement(modal) || modal.querySelector('[role="dialog"]');
		if (focusTarget) {
			focusTarget.focus();
		}
	}

	function closeModal() {
		if (!activeModal) {
			return;
		}

		activeModal.hidden = true;
		document.body.classList.remove('zmm-cancel-modal-open');

		if (activeModalParent) {
			activeModalParent.insertBefore(activeModal, activeModalNextSibling);
		}

		if (activeTrigger) {
			activeTrigger.focus();
		}

		activeModal = null;
		activeModalParent = null;
		activeModalNextSibling = null;
		activeTrigger = null;
	}

	document.addEventListener('click', function (event) {
		var trigger = event.target.closest('[data-zmm-cancel-trigger]');
		var dismiss = event.target.closest('[data-zmm-cancel-dismiss]');

		if (trigger) {
			event.preventDefault();
			openModal(trigger);
			return;
		}

		if (dismiss) {
			event.preventDefault();
			closeModal();
		}
	});

	document.addEventListener('keydown', function (event) {
		if (event.key === 'Escape') {
			closeModal();
		}
	});
}());
