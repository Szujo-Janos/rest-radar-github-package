(function () {
	'use strict';

	function setButtonFeedback(button, text, timeout) {
		var original = button.getAttribute('data-original-label') || button.textContent;
		button.setAttribute('data-original-label', original);
		button.textContent = text;
		window.setTimeout(function () {
			button.textContent = original;
		}, timeout || 1400);
	}

	function getCopyText(target) {
		if (!target) {
			return '';
		}

		if (typeof target.value === 'string') {
			return target.value;
		}

		return target.textContent || '';
	}

	function fallbackCopy(text) {
		var temporary = document.createElement('textarea');
		temporary.value = text;
		temporary.setAttribute('readonly', 'readonly');
		temporary.style.position = 'fixed';
		temporary.style.top = '-9999px';
		temporary.style.left = '-9999px';
		document.body.appendChild(temporary);
		temporary.focus();
		temporary.select();
		document.execCommand('copy');
		document.body.removeChild(temporary);
	}

	document.addEventListener('click', function (event) {
		var copyButton = event.target.closest('.rest-radar-copy-button');
		if (copyButton) {
			event.preventDefault();
			var copyTarget = document.getElementById(copyButton.getAttribute('data-target'));
			if (!copyTarget) {
				return;
			}
			var value = getCopyText(copyTarget);
			if (navigator.clipboard && window.isSecureContext) {
				navigator.clipboard.writeText(value).then(function () {
					setButtonFeedback(copyButton, 'Copied');
				}).catch(function () {
					fallbackCopy(value);
					setButtonFeedback(copyButton, 'Copied');
				});
			} else {
				fallbackCopy(value);
				setButtonFeedback(copyButton, 'Copied');
			}
			return;
		}

		var expandButton = event.target.closest('.rest-radar-expand-button');
		if (expandButton) {
			event.preventDefault();
			var expandTarget = document.getElementById(expandButton.getAttribute('data-target'));
			if (!expandTarget) {
				return;
			}
			var expanded = expandTarget.classList.toggle('rest-radar-snippet-expanded');
			expandButton.setAttribute('aria-expanded', expanded ? 'true' : 'false');
			expandButton.textContent = expanded
				? (expandButton.getAttribute('data-collapse-label') || 'Collapse')
				: (expandButton.getAttribute('data-expand-label') || 'Expand');
		}
	});
}());
