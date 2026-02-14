/**
 * WP AI Code — Admin JavaScript.
 */
(function () {
	'use strict';

	document.addEventListener('DOMContentLoaded', function () {
		var forms = document.querySelectorAll('.wpaic-action-form');

		forms.forEach(function (form) {
			form.addEventListener('submit', function (e) {
				var confirmType = form.getAttribute('data-confirm');
				var message = '';

				if (confirmType && wpaicAdmin && wpaicAdmin.i18n) {
					switch (confirmType) {
						case 'approve':
							message = wpaicAdmin.i18n.confirmApprove;
							break;
						case 'reject':
							message = wpaicAdmin.i18n.confirmReject;
							break;
						case 'rollback':
							message = wpaicAdmin.i18n.confirmRollback;
							break;
					}
				}

				if (message && !window.confirm(message)) {
					e.preventDefault();
				}
			});
		});
	});
})();
