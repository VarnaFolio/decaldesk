(function ($) {
	'use strict';

	$(function () {
		var $list = $('#decaldesk-categories-list');
		var cardTemplate = $('#decaldesk-category-card-template').html();
		var slotTemplate = $('#decaldesk-template-slot-template').html();
		var MAX_SLOTS = 4;

		// Следи кои слотове имат преместена, но НЕзапазена зона (ключ: "slug:slot") -
		// за да предупредим потребителя при опит да напусне страницата с
		// незаписани промени.
		var dirtySlots = {};

		window.addEventListener('beforeunload', function (e) {
			if (Object.keys(dirtySlots).length > 0) {
				e.preventDefault();
				e.returnValue = '';
				return '';
			}
		});

		// ==========================================================
		// Добавяне на нова категория
		// ==========================================================
		var $nameInput = $('#decaldesk-new-category-name');
		var $slugInput = $('#decaldesk-new-category-slug');
		var slugManuallyEdited = false;

		$slugInput.on('input', function () {
			slugManuallyEdited = true;
		});

		$nameInput.on('input', function () {
			if (!slugManuallyEdited) {
				$slugInput.val(slugify($nameInput.val()));
			}
		});

		function slugify(text) {
			var map = {
				'а':'a','б':'b','в':'v','г':'g','д':'d','е':'e','ж':'zh','з':'z','и':'i','й':'y',
				'к':'k','л':'l','м':'m','н':'n','о':'o','п':'p','р':'r','с':'s','т':'t','у':'u',
				'ф':'f','х':'h','ц':'ts','ч':'ch','ш':'sh','щ':'sht','ъ':'a','ь':'y','ю':'yu','я':'ya'
			};
			var result = text.toLowerCase().split('').map(function (ch) {
				return map[ch] !== undefined ? map[ch] : ch;
			}).join('');
			return result
				.replace(/[^a-z0-9\s-]/g, '')
				.trim()
				.replace(/\s+/g, '-')
				.replace(/-+/g, '-');
		}

		$('#decaldesk-add-category-btn').on('click', function () {
			var name = $nameInput.val().trim();
			var slug = $slugInput.val().trim();

			if (!name || !slug) {
				alert('Please fill in a name and slug.');
				return;
			}

			var $btn = $(this).prop('disabled', true);

			$.post(DecalDeskCategoriesData.ajax_url, {
				action: 'decaldesk_add_category',
				nonce: DecalDeskCategoriesData.nonce,
				name: name,
				slug: slug
			}).done(function (response) {
				$btn.prop('disabled', false);

				if (!response.success) {
					alert(response.data.message || 'An error occurred.');
					return;
				}

				var html = cardTemplate
					.replace(/__SLUG__/g, response.data.slug)
					.replace(/__NAME__/g, escapeHtml(response.data.name));

				var $newCard = $(html);
				var $newImg = $newCard.find('.decaldesk-template-preview-img').attr('src', response.data.preview_url);
				applyWrapAspectRatio($newImg);
				$list.append($newCard);
				$('.decaldesk-empty-state').remove();

				$nameInput.val('');
				$slugInput.val('');
				slugManuallyEdited = false;
			}).fail(function () {
				$btn.prop('disabled', false);
				alert('Server connection error.');
			});
		});

		function escapeHtml(text) {
			return $('<div>').text(text).html();
		}

		// Слага aspect-ratio на preview кутията, равно на реалното съотношение
		// на качената снимка на шаблона. Без това, wrap-ът е фиксиран квадрат
		// и object-fit:contain "letterbox"-ва не-квадратни снимки (празни ленти
		// горе/долу или отстрани) - тогава кликването за точки/зона се
		// изчислява спрямо целия квадрат, а не спрямо реално видимата снимка,
		// и позицията излиза изместена (типично "по-надолу") спрямо реалния
		// мокъп, който после се генерира върху пълните размери на снимката.
		function applyWrapAspectRatio($img) {
			var img = $img[0];
			if (!img) {
				return;
			}

			function apply() {
				if (img.naturalWidth && img.naturalHeight) {
					$img.closest('.decaldesk-template-preview-wrap')
						.css('aspect-ratio', img.naturalWidth + ' / ' + img.naturalHeight);
				}
			}

			if (img.complete) {
				apply();
			} else {
				$img.on('load', apply);
			}
		}

		// Прилагаме върху всички preview снимки, вече заредени в страницата.
		$list.find('.decaldesk-template-preview-img').each(function () {
			applyWrapAspectRatio($(this));
		});

		// ==========================================================
		// Категорийни действия (преименуване, изтриване) - ниво card
		// ==========================================================

		$list.on('blur', '.decaldesk-category-name-input', function () {
			var $card = $(this).closest('.decaldesk-category-card');
			var slug = $card.data('slug');
			var name = $(this).val().trim();

			if (!name) {
				return;
			}

			$.post(DecalDeskCategoriesData.ajax_url, {
				action: 'decaldesk_rename_category',
				nonce: DecalDeskCategoriesData.nonce,
				slug: slug,
				name: name
			});
		});

		$list.on('click', '.decaldesk-delete-category', function () {
			var $card = $(this).closest('.decaldesk-category-card');
			var slug = $card.data('slug');

			if (!confirm('Delete the category "' + slug + '"? Real products in the store are NOT deleted.')) {
				return;
			}

			$.post(DecalDeskCategoriesData.ajax_url, {
				action: 'decaldesk_delete_category',
				nonce: DecalDeskCategoriesData.nonce,
				slug: slug
			}).done(function (response) {
				if (response.success) {
					$card.slideUp(200, function () {
						$card.remove();
						if ($list.children().length === 0) {
							$list.append('<p class="decaldesk-empty-state">No categories added yet.</p>');
						}
					});
				}
			});
		});

		// ==========================================================
		// Добавяне / изтриване на template слот
		// ==========================================================

		$list.on('click', '.decaldesk-add-template-slot', function () {
			var $btn = $(this);
			var $card = $btn.closest('.decaldesk-category-card');
			var slug = $card.data('slug');
			var $slotsContainer = $card.find('.decaldesk-template-slots');
			var currentCount = $slotsContainer.find('.decaldesk-template-slot').length;

			if (currentCount >= MAX_SLOTS) {
				return;
			}

			var nextSlot = currentCount + 1;
			var html = slotTemplate
				.replace(/__SLUG__/g, slug)
				.replace(/__SLOT__/g, nextSlot);

			$slotsContainer.append($(html));

			if (nextSlot >= MAX_SLOTS) {
				$btn.prop('disabled', true);
			}
		});

		$list.on('click', '.decaldesk-delete-slot', function () {
			var $slot = $(this).closest('.decaldesk-template-slot');
			var slug = $slot.data('slug');
			var slot = $slot.data('slot');
			var $card = $slot.closest('.decaldesk-category-card');
			var totalSlots = $card.find('.decaldesk-template-slot').length;

			if (totalSlots <= 1) {
				alert('The category needs at least one template (even the default). Delete the whole category if you don\'t need it.');
				return;
			}

			if (!confirm('Delete template ' + slot + '?')) {
				return;
			}

			$.post(DecalDeskCategoriesData.ajax_url, {
				action: 'decaldesk_delete_template_slot',
				nonce: DecalDeskCategoriesData.nonce,
				slug: slug,
				slot: slot
			}).done(function (response) {
				if (response.success) {
					// Презареждаме страницата, за да сме сигурни, че номерацията
					// на слотовете (renumbering на сървъра) съвпада точно с
					// това, което виждаме - по-просто и по-сигурно от ръчна
					// синхронизация на всички засегнати слот-номера в DOM-а.
					dirtySlots = {};
					window.location.reload();
				} else {
					alert((response.data && response.data.message) || 'Error deleting.');
				}
			});
		});

		// ==========================================================
		// Качване на шаблон за конкретен слот
		// ==========================================================
		$list.on('change', '.decaldesk-template-upload-input', function () {
			var $slot = $(this).closest('.decaldesk-template-slot');
			var slug = $slot.data('slug');
			var slot = $slot.data('slot');
			var file = this.files[0];

			if (!file) {
				return;
			}

			var formData = new FormData();
			formData.append('action', 'decaldesk_upload_template');
			formData.append('nonce', DecalDeskCategoriesData.nonce);
			formData.append('slug', slug);
			formData.append('slot', slot);
			formData.append('template', file);

			showSaveStatus($slot, null, 'Uploading template...');

			$.ajax({
				url: DecalDeskCategoriesData.ajax_url,
				type: 'POST',
				data: formData,
				processData: false,
				contentType: false,
				global: false,
				success: function (response) {
					if (response.success) {
						var $wrap = $slot.find('.decaldesk-template-preview-wrap');
						var $img = $wrap.find('.decaldesk-template-preview-img');

						if ($img.length === 0) {
							$wrap.find('.decaldesk-template-preview-placeholder').remove();
							$img = $('<img class="decaldesk-template-preview-img" alt="">');
							$wrap.prepend($img);
						}
						$img.attr('src', response.data.preview_url);
						applyWrapAspectRatio($img);

						$slot.find('.decaldesk-template-status').html(
							'<span class="dashicons dashicons-yes-alt" style="color:#00a32a;"></span> Custom template uploaded'
						);
						showSaveStatus($slot, true, 'Template uploaded.');
					} else {
						showSaveStatus($slot, false, response.data.message);
					}
				},
				error: function () {
					showSaveStatus($slot, false, 'Error uploading.');
				}
			});
		});

		// Тестов дизайн - зарежда се САМО локално в браузъра (FileReader),
		// никога не се качва на сървъра - чисто за визуален preview.
		$list.on('change', '.decaldesk-zone-test-input', function () {
			var $slot = $(this).closest('.decaldesk-template-slot');
			var file = this.files[0];

			if (!file) {
				return;
			}

			var reader = new FileReader();
			reader.onload = function (e) {
				$slot.find('.decaldesk-zone-test-preview').attr('src', e.target.result).show();

				var $polyPreview = $slot.find('.decaldesk-zone-polygon-test-preview');
				$polyPreview.attr('src', e.target.result).show();
				updatePolygonClipPath($slot);
			};
			reader.readAsDataURL(file);
		});

		// ==========================================================
		// Превключвател "Rectangle" / "Freeform"
		// ==========================================================
		$list.on('change', '.decaldesk-zone-mode-radio', function () {
			var $radio = $(this);
			var $slot = $radio.closest('.decaldesk-template-slot');
			var mode = $radio.val();

			$slot.attr('data-zone-type', mode);
			$slot.find('.decaldesk-zone-box').toggle(mode === 'rect');
			$slot.find('.decaldesk-zone-polygon-wrap').toggle(mode === 'polygon');
			$slot.find('.decaldesk-rect-only-control').toggle(mode === 'rect');
			$slot.find('.decaldesk-polygon-only-control').toggle(mode === 'polygon');

			markDirty($slot);
		});

		// ==========================================================
		// Полигонален редактор - добавяне на точки с клик
		// ==========================================================
		$list.on('click', '.decaldesk-zone-polygon-wrap', function (e) {
			// Игнорираме клик върху вече поставена точка (тя си има собствен mousedown за влачене)
			if ($(e.target).hasClass('decaldesk-zone-polygon-point')) {
				return;
			}

			var $wrap = $(this);
			var $slot = $wrap.closest('.decaldesk-template-slot');
			var rect = $wrap[0].getBoundingClientRect();

			var xPercent = clamp(((e.clientX - rect.left) / rect.width) * 100, 0, 100);
			var yPercent = clamp(((e.clientY - rect.top) / rect.height) * 100, 0, 100);

			addPolygonPoint($slot, xPercent, yPercent);
			markDirty($slot);
		});

		// Влачене на съществуваща точка
		$list.on('mousedown', '.decaldesk-zone-polygon-point', function (e) {
			e.preventDefault();
			e.stopPropagation();

			var $point = $(this);
			var $slot = $point.closest('.decaldesk-template-slot');
			var $wrap = $slot.find('.decaldesk-zone-polygon-wrap');
			var wrapRect = $wrap[0].getBoundingClientRect();
			var moved = false;

			function onMouseMove(e) {
				moved = true;
				var xPercent = clamp(((e.clientX - wrapRect.left) / wrapRect.width) * 100, 0, 100);
				var yPercent = clamp(((e.clientY - wrapRect.top) / wrapRect.height) * 100, 0, 100);

				$point.css({ left: xPercent + '%', top: yPercent + '%' });
				updatePolygonVisuals($slot);
			}

			function onMouseUp() {
				$(document).off('mousemove', onMouseMove);
				$(document).off('mouseup', onMouseUp);
				if (moved) {
					markDirty($slot);
				}
			}

			$(document).on('mousemove', onMouseMove);
			$(document).on('mouseup', onMouseUp);
		});

		// "Clear points"
		$list.on('click', '.decaldesk-clear-polygon-btn', function () {
			var $slot = $(this).closest('.decaldesk-template-slot');
			$slot.find('.decaldesk-zone-polygon-points').empty();
			updatePolygonVisuals($slot);
			markDirty($slot);
		});

		function addPolygonPoint($slot, xPercent, yPercent) {
			var $points = $slot.find('.decaldesk-zone-polygon-points');
			var index = $points.find('.decaldesk-zone-polygon-point').length;

			var $point = $('<span class="decaldesk-zone-polygon-point"></span>')
				.attr('data-index', index)
				.css({ left: xPercent + '%', top: yPercent + '%' });

			$points.append($point);
			updatePolygonVisuals($slot);
		}

		function getSlotPoints($slot) {
			var points = [];
			$slot.find('.decaldesk-zone-polygon-point').each(function () {
				points.push({
					x: parseFloat($(this).css('left')) || 0,
					y: parseFloat($(this).css('top')) || 0
				});
			});
			return points;
		}

		// Обновява SVG полигона + clip-path на тестовия preview, спрямо
		// текущите позиции на точките в DOM-а.
		function updatePolygonVisuals($slot) {
			var points = getSlotPoints($slot);
			var svgPointsStr = points.map(function (p) { return p.x + ',' + p.y; }).join(' ');

			$slot.find('.decaldesk-zone-polygon-shape').attr('points', svgPointsStr);

			updatePolygonClipPath($slot, points);
		}

		function updatePolygonClipPath($slot, points) {
			points = points || getSlotPoints($slot);
			var $preview = $slot.find('.decaldesk-zone-polygon-test-preview');

			if (points.length < 3) {
				$preview.css('clip-path', 'none');
				return;
			}

			var clipPath = 'polygon(' + points.map(function (p) { return p.x + '% ' + p.y + '%'; }).join(', ') + ')';
			$preview.css('clip-path', clipPath);
		}

		// ==========================================================
		// Запазване / нулиране на позицията на зоната (ниво слот)
		// ==========================================================
		$list.on('click', '.decaldesk-save-zone-btn', function () {
			var $slot = $(this).closest('.decaldesk-template-slot');
			var slug = $slot.data('slug');
			var slot = $slot.data('slot');
			var mode = $slot.attr('data-zone-type') || 'rect';

			showSaveStatus($slot, null, 'Saving...');

			var postData = {
				action: 'decaldesk_save_zone',
				nonce: DecalDeskCategoriesData.nonce,
				slug: slug,
				slot: slot,
				type: mode
			};

			if (mode === 'polygon') {
				var points = getSlotPoints($slot);
				if (points.length < 3) {
					showSaveStatus($slot, false, 'At least 3 points are required for a freeform shape.');
					return;
				}
				postData.points = JSON.stringify(points);
			} else {
				var $box = $slot.find('.decaldesk-zone-box');
				postData.x = parseFloat($box[0].style.left) || 0;
				postData.y = parseFloat($box[0].style.top) || 0;
				postData.width = parseFloat($box[0].style.width) || 70;
				postData.height = parseFloat($box[0].style.height) || 70;
			}

			$.post(DecalDeskCategoriesData.ajax_url, postData).done(function (response) {
				showSaveStatus($slot, response.success, response.success ? 'Position saved.' : ((response.data && response.data.message) || 'Error saving.'));
				if (response.success) {
					markClean($slot);
				}
			});
		});

		$list.on('click', '.decaldesk-reset-zone-btn', function () {
			var $slot = $(this).closest('.decaldesk-template-slot');
			var $box = $slot.find('.decaldesk-zone-box');

			$box.css({ left: '15%', top: '15%', width: '70%', height: '70%' });
			markDirty($slot);
		});

		function showSaveStatus($slot, success, message) {
			var $status = $slot.find('.decaldesk-category-save-status');

			if (null === success) {
				$status.removeClass('is-success is-error').text(message || '').show();
				return;
			}

			$status
				.toggleClass('is-success', success)
				.toggleClass('is-error', !success)
				.text(message || (success ? 'Saved.' : 'Error.'))
				.show();

			setTimeout(function () {
				$status.fadeOut(300);
			}, 2000);
		}

		// ==========================================================
		// Drag & resize логика за zone box-а (ниво слот)
		// ==========================================================
		$list.on('mousedown', '.decaldesk-zone-box', function (e) {
			if ($(e.target).hasClass('decaldesk-zone-handle')) {
				return;
			}

			e.preventDefault();
			var $box = $(this);
			var $wrap = $box.closest('.decaldesk-template-preview-wrap');
			var wrapRect = $wrap[0].getBoundingClientRect();

			var startX = e.clientX;
			var startY = e.clientY;
			var startLeft = parseFloat($box[0].style.left) || 0;
			var startTop = parseFloat($box[0].style.top) || 0;
			var moved = false;

			function onMouseMove(e) {
				moved = true;
				var deltaXPercent = ((e.clientX - startX) / wrapRect.width) * 100;
				var deltaYPercent = ((e.clientY - startY) / wrapRect.height) * 100;

				var newLeft = clamp(startLeft + deltaXPercent, 0, 100 - parseFloat($box[0].style.width));
				var newTop = clamp(startTop + deltaYPercent, 0, 100 - parseFloat($box[0].style.height));

				$box.css({ left: newLeft + '%', top: newTop + '%' });
			}

			function onMouseUp() {
				$(document).off('mousemove', onMouseMove);
				$(document).off('mouseup', onMouseUp);
				if (moved) {
					markDirty($box.closest('.decaldesk-template-slot'));
				}
			}

			$(document).on('mousemove', onMouseMove);
			$(document).on('mouseup', onMouseUp);
		});

		$list.on('mousedown', '.decaldesk-zone-handle', function (e) {
			e.preventDefault();
			e.stopPropagation();

			var $handle = $(this);
			var $box = $handle.closest('.decaldesk-zone-box');
			var $wrap = $box.closest('.decaldesk-template-preview-wrap');
			var wrapRect = $wrap[0].getBoundingClientRect();

			var corner = $handle.hasClass('decaldesk-zone-handle-nw') ? 'nw'
				: $handle.hasClass('decaldesk-zone-handle-ne') ? 'ne'
				: $handle.hasClass('decaldesk-zone-handle-sw') ? 'sw'
				: 'se';

			var startX = e.clientX;
			var startY = e.clientY;
			var startLeft = parseFloat($box[0].style.left) || 0;
			var startTop = parseFloat($box[0].style.top) || 0;
			var startWidth = parseFloat($box[0].style.width) || 70;
			var startHeight = parseFloat($box[0].style.height) || 70;
			var moved = false;

			function onMouseMove(e) {
				moved = true;
				var deltaXPercent = ((e.clientX - startX) / wrapRect.width) * 100;
				var deltaYPercent = ((e.clientY - startY) / wrapRect.height) * 100;

				var newLeft = startLeft, newTop = startTop, newWidth = startWidth, newHeight = startHeight;

				if (corner === 'se') {
					newWidth = clamp(startWidth + deltaXPercent, 5, 100 - startLeft);
					newHeight = clamp(startHeight + deltaYPercent, 5, 100 - startTop);
				} else if (corner === 'sw') {
					newWidth = clamp(startWidth - deltaXPercent, 5, startLeft + startWidth);
					newLeft = startLeft + startWidth - newWidth;
					newHeight = clamp(startHeight + deltaYPercent, 5, 100 - startTop);
				} else if (corner === 'ne') {
					newWidth = clamp(startWidth + deltaXPercent, 5, 100 - startLeft);
					newHeight = clamp(startHeight - deltaYPercent, 5, startTop + startHeight);
					newTop = startTop + startHeight - newHeight;
				} else if (corner === 'nw') {
					newWidth = clamp(startWidth - deltaXPercent, 5, startLeft + startWidth);
					newLeft = startLeft + startWidth - newWidth;
					newHeight = clamp(startHeight - deltaYPercent, 5, startTop + startHeight);
					newTop = startTop + startHeight - newHeight;
				}

				$box.css({ left: newLeft + '%', top: newTop + '%', width: newWidth + '%', height: newHeight + '%' });
			}

			function onMouseUp() {
				$(document).off('mousemove', onMouseMove);
				$(document).off('mouseup', onMouseUp);
				if (moved) {
					markDirty($box.closest('.decaldesk-template-slot'));
				}
			}

			$(document).on('mousemove', onMouseMove);
			$(document).on('mouseup', onMouseUp);
		});

		function slotKey($slot) {
			return $slot.data('slug') + ':' + $slot.data('slot');
		}

		function markDirty($slot) {
			$slot.addClass('decaldesk-zone-dirty');
			$slot.find('.decaldesk-save-zone-btn').addClass('decaldesk-save-zone-btn-pulse');
			dirtySlots[slotKey($slot)] = true;
		}

		function markClean($slot) {
			$slot.removeClass('decaldesk-zone-dirty');
			$slot.find('.decaldesk-save-zone-btn').removeClass('decaldesk-save-zone-btn-pulse');
			delete dirtySlots[slotKey($slot)];
		}

		function clamp(value, min, max) {
			return Math.max(min, Math.min(max, value));
		}
	});
})(jQuery);
