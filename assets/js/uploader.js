(function ($) {
	'use strict';

	$(function () {
		var $dropzone = $('#decaldesk-dropzone');
		var $fileInput = $('#decaldesk-file-input');
		var $uploadBtn = $('#decaldesk-upload-btn');
		var $progress = $('#decaldesk-progress');
		var $progressBar = $progress.find('.decaldesk-progress-bar');
		var $progressLabel = $('#decaldesk-progress-label');
		var $results = $('#decaldesk-results');
		var $fileSummary = $('#decaldesk-file-summary');
		var $fileCount = $('#decaldesk-file-count');
		var $fileList = $('#decaldesk-file-list');
		var $clearBtn = $('#decaldesk-clear-files');
		var $summary = $('#decaldesk-summary');

		var MAX_FILES = (window.DecalDeskData && parseInt(DecalDeskData.maxFiles, 10)) || 50;
		var ALLOWED_MIME_TYPES = ['image/png', 'image/jpeg', 'image/webp', 'image/gif'];
		var CONFIGURED_CATEGORIES = (window.DecalDeskData && DecalDeskData.categories) || {};
		var MAX_DIMENSION_CM = (window.DecalDeskData && parseInt(DecalDeskData.maxDimensionCm, 10)) || 1000;

		// ==========================================================
		// Live преглед/валидация на файловото име, ПРЕДИ реалното качване -
		// огледва decaldesk_parse_filename() (includes/parser.php), за да
		// хване грешки във формата на името (или нереалистичен размер)
		// веднага при избор/drop на файла, вместо след неуспешен upload опит.
		// Категорията, ако не съвпада с конфигурираните, е само предупреждение
		// (не грешка) - сървърът все пак ще създаде продукта с generic мокъп.
		// ==========================================================
		var FILENAME_PATTERN = /^(.+)_(\d+)x(\d+)_([a-zA-Z0-9]+)_([a-zA-Z0-9\-]+)$/;

		function parseFilenameClientSide(filename) {
			var base = filename.replace(/\.[^.\/\\]+$/, '');
			var match = base.match(FILENAME_PATTERN);

			if (!match) {
				return {
					ok: false,
					message: 'Doesn\'t match the format name_widthxheight_material_category.extension'
				};
			}

			var width = parseInt(match[2], 10);
			var height = parseInt(match[3], 10);
			var material = match[4].toLowerCase();
			var category = match[5].toLowerCase();

			if (width <= 0 || height <= 0) {
				return { ok: false, message: 'Dimensions must be positive numbers.' };
			}

			if (width > MAX_DIMENSION_CM || height > MAX_DIMENSION_CM) {
				return {
					ok: false,
					message: width + ' x ' + height + ' cm looks unrealistically large (maximum ' + MAX_DIMENSION_CM + ' cm per side). Check for a typo.'
				};
			}

			var prettyName = match[1].replace(/[_-]/g, ' ').trim();
			prettyName = prettyName.charAt(0).toUpperCase() + prettyName.slice(1);

			var categoryKnown = Object.prototype.hasOwnProperty.call(CONFIGURED_CATEGORIES, category);

			return {
				ok: true,
				name: prettyName,
				width: width,
				height: height,
				material: material,
				category: category,
				categoryKnown: categoryKnown,
				warning: categoryKnown ? '' : 'Category "' + category + '" isn\'t configured yet — a generic mockup will be used.'
			};
		}

		var selectedFiles = [];

		// ==========================================================
		// Съхранение на активните job-ове в localStorage - ако администраторът
		// презареди/затвори таба по средата на batch-а, обработката продължава
		// на сървъра, но без това live-проследяването в JS би се изгубило и
		// единственият начин да се провери резултатът би бил ръчно през
		// DecalDesk → History. Записът се трие веднага щом всички job-ове
		// приключат (успешно или с грешка).
		var ACTIVE_JOBS_STORAGE_KEY = 'decaldesk_active_jobs';
		var ACTIVE_JOBS_MAX_AGE_MS = 15 * 60 * 1000; // съответства на maxPolls таймаута по-долу, с известен запас

		function saveActiveJobsToStorage(jobRows, uploadStats) {
			var filenames = {};
			Object.keys(jobRows).forEach(function (jobId) {
				filenames[jobId] = jobRows[jobId].filename;
			});

			try {
				localStorage.setItem(ACTIVE_JOBS_STORAGE_KEY, JSON.stringify({
					filenames: filenames,
					uploadStats: uploadStats,
					savedAt: Date.now()
				}));
			} catch (e) {
				// localStorage недостъпен (private mode, квота и т.н.) - live прогресът
				// просто няма да оцелее презареждане, но качването си работи нормално.
			}
		}

		function clearActiveJobsFromStorage() {
			try {
				localStorage.removeItem(ACTIVE_JOBS_STORAGE_KEY);
			} catch (e) {
				// нищо за правене
			}
		}

		function loadActiveJobsFromStorage() {
			var raw;
			try {
				raw = localStorage.getItem(ACTIVE_JOBS_STORAGE_KEY);
			} catch (e) {
				return null;
			}

			if (!raw) {
				return null;
			}

			var parsed;
			try {
				parsed = JSON.parse(raw);
			} catch (e) {
				return null;
			}

			if (!parsed || !parsed.filenames || !parsed.savedAt || (Date.now() - parsed.savedAt) > ACTIVE_JOBS_MAX_AGE_MS) {
				clearActiveJobsFromStorage();
				return null;
			}

			if (Object.keys(parsed.filenames).length === 0) {
				clearActiveJobsFromStorage();
				return null;
			}

			return parsed;
		}

		// При зареждане на страницата: ако има запазени активни job-ове от
		// прекъснато проследяване, пресъздаваме резултатните редове и веднага
		// подновяваме поллинга - вместо администраторът да гадае дали качването
		// изобщо е минало.
		(function resumeActiveJobsIfAny() {
			var stored = loadActiveJobsFromStorage();
			if (!stored) {
				return;
			}

			var jobRows = {};
			Object.keys(stored.filenames).forEach(function (jobId) {
				var $row = createQueuedResultRow(stored.filenames[jobId]);
				jobRows[jobId] = { $row: $row, filename: stored.filenames[jobId] };
			});

			$progress.show();
			$progressBar.css('width', '50%'); // качването вече е приключило преди презареждането
			$progressLabel.text('Resuming progress tracking after reload...');
			$uploadBtn.prop('disabled', true);

			pollJobStatuses(jobRows, stored.uploadStats);
		})();

		// Клик върху dropzone отваря file picker (само ако кликът НЕ идва от самия input)
		$dropzone.on('click', function (e) {
			if (e.target === $fileInput[0]) {
				return;
			}
			$fileInput.trigger('click');
		});

		// Клавиатурен еквивалент на click-а по-горе - dropzone-ът е фокусируем div
		// (role="button"), Enter/Space трябва да отварят file picker-а както при мишка.
		$dropzone.on('keydown', function (e) {
			if (e.key === 'Enter' || e.key === ' ' || e.key === 'Spacebar') {
				e.preventDefault();
				$fileInput.trigger('click');
			}
		});

		// Предпазител: спираме bubbling-а на click от input-а, за да не се
		// провокира отново click handler-a на dropzone (безкраен цикъл).
		$fileInput.on('click', function (e) {
			e.stopPropagation();
		});

		// Избор на файлове чрез диалог
		$fileInput.on('change', function (e) {
			addFiles(e.target.files);
			// Нулираме input-a, за да може да се избере отново същия файл при нужда
			$fileInput.val('');
		});

		// Drag & drop събития
		$dropzone.on('dragover', function (e) {
			e.preventDefault();
			e.stopPropagation();
			$dropzone.addClass('is-dragover');
		});

		$dropzone.on('dragleave drop', function (e) {
			e.preventDefault();
			e.stopPropagation();
			$dropzone.removeClass('is-dragover');
		});

		$dropzone.on('drop', function (e) {
			var files = e.originalEvent.dataTransfer.files;
			addFiles(files);
		});

		$clearBtn.on('click', function () {
			selectedFiles = [];
			renderFileList();
		});

		/*! <fs_premium_only> */
		// ==========================================================
		// Разгъваема секция за размерни варианти (размер/материал/цвят)
		// ==========================================================
		var $toggleVariantBtn = $('#decaldesk-toggle-variant-config');
		var $variantPanel = $('#decaldesk-variant-config-panel');
		var $useVariantsCheckbox = $('#decaldesk-use-variants');

		$toggleVariantBtn.on('click', function () {
			$variantPanel.slideToggle(150);
		});

		// Чекването на "Създай с избираеми варианти" автоматично отваря панела
		// с размери/материали/цветове - потребителят не трябва отделно да го
		// търси/отваря сам, за да си довърши конфигурацията.
		$useVariantsCheckbox.on('change', function () {
			if ($(this).is(':checked') && !$variantPanel.is(':visible')) {
				$variantPanel.slideDown(150);
			}
		});

		/**
		 * Записва текущото съдържание на полетата размери/материали/цветове
		 * в базата. Връща jQuery Deferred (resolve-ва се и при success:false
		 * отговор от сървъра - викащият код проверява response.success).
		 * Използва се както от ръчния бутон "Save", така и автоматично при
		 * клик на "Upload files" (виж по-долу).
		 */
		function saveVariantConfig() {
			var sizes = $('#decaldesk-variant-sizes-input').val();
			var materials = $('#decaldesk-variant-materials-input').val();
			var colors = $('#decaldesk-variant-colors-input').val();

			return $.post(DecalDeskData.ajax_url, {
				action: 'decaldesk_save_variant_config',
				nonce: DecalDeskData.nonce,
				sizes: sizes,
				materials: materials,
				colors: colors
			}).done(function (response) {
				if (!response.success) {
					return;
				}

				var savedSizes = response.data.sizes || [];

				// Обновяваме текстовите полета с реално запазените (санитизирани) стойности
				$('#decaldesk-variant-sizes-input').val(savedSizes.join('\n'));
				$('#decaldesk-variant-materials-input').val((response.data.materials || []).join(', '));
				$('#decaldesk-variant-colors-input').val((response.data.colors || []).join(', '));

				// Динамично активираме/деактивираме чекбокса според това дали има размери
				$useVariantsCheckbox.prop('disabled', savedSizes.length === 0);

				if (savedSizes.length > 0) {
					$('#decaldesk-variants-summary').text('Every uploaded design will become one product with a choice of width: ' + savedSizes.join(', ') + ' cm (height is calculated automatically to match each design\'s proportions).');
				} else {
					$useVariantsCheckbox.prop('checked', false);
					$('#decaldesk-variants-summary').text('No variant widths configured yet — add at least one below to enable this option.');
				}
			});
		}

		$('#decaldesk-save-variant-config-btn').on('click', function () {
			var $btn = $(this);
			var $status = $('#decaldesk-variant-config-status');

			$btn.prop('disabled', true);
			$status.removeClass('is-success is-error').text('Saving...');

			saveVariantConfig().done(function (response) {
				$btn.prop('disabled', false);

				if (!response.success) {
					$status.addClass('is-error').text((response.data && response.data.message) || 'Error saving.');
					return;
				}

				$status.addClass('is-success').text('Saved.');
				setTimeout(function () {
					$status.fadeOut(300, function () { $status.show().removeClass('is-success').text(''); });
				}, 2000);
			}).fail(function () {
				$btn.prop('disabled', false);
				$status.addClass('is-error').text('Server connection error.');
			});
		});
		/*! </fs_premium_only> */

		function addFiles(fileList) {
			var skippedBadType = 0;
			var skippedDuplicate = 0;
			var skippedLimit = 0;

			for (var i = 0; i < fileList.length; i++) {
				var file = fileList[i];

				if (ALLOWED_MIME_TYPES.indexOf(file.type) === -1) {
					skippedBadType++;
					continue;
				}

				var isDuplicate = selectedFiles.some(function (f) {
					return f.name === file.name && f.size === file.size;
				});
				if (isDuplicate) {
					skippedDuplicate++;
					continue;
				}

				if (selectedFiles.length >= MAX_FILES) {
					skippedLimit++;
					continue;
				}

				selectedFiles.push(file);
			}

			renderFileList();

			var messages = [];
			if (skippedLimit) {
				messages.push('The limit of ' + MAX_FILES + ' files — ' + skippedLimit + ' files were not added.');
			}
			if (skippedBadType) {
				messages.push(skippedBadType + ' files are in an unsupported format (allowed: PNG, JPG, WEBP, GIF) and were skipped.');
			}
			if (skippedDuplicate) {
				messages.push(skippedDuplicate + ' files were already added and were skipped.');
			}
			if (messages.length) {
				alert(messages.join('\n'));
			}
		}

		function removeFile(index) {
			selectedFiles.splice(index, 1);
			renderFileList();
		}

		function renderFileList() {
			$fileList.empty();

			if (!selectedFiles.length) {
				$fileSummary.hide();
				return;
			}

			var invalidCount = 0;

			var $ul = $('<ul class="decaldesk-selected-files"></ul>');
			selectedFiles.forEach(function (file, index) {
				var parsed = parseFilenameClientSide(file.name);
				var $li = $('<li class="decaldesk-selected-file-row"></li>');

				var $nameRow = $('<div class="decaldesk-file-name-row"></div>');
				$nameRow.append('<span class="decaldesk-file-name">' + escapeHtml(file.name) + '</span>');
				var $remove = $('<button type="button" class="decaldesk-remove-file" aria-label="Remove">&times;</button>');
				$remove.on('click', function () {
					removeFile(index);
				});
				$nameRow.append($remove);
				$li.append($nameRow);

				if (!parsed.ok) {
					invalidCount++;
					$li.addClass('decaldesk-file-invalid');
					$li.append('<div class="decaldesk-file-parse-note decaldesk-file-parse-error">⚠ ' + escapeHtml(parsed.message) + '</div>');
				} else {
					var preview = parsed.name + ' · ' + parsed.width + '×' + parsed.height + ' cm · ' + parsed.material + ' · ' + parsed.category;
					$li.append('<div class="decaldesk-file-parse-note decaldesk-file-parse-ok">✓ ' + escapeHtml(preview) + '</div>');
					if (parsed.warning) {
						$li.addClass('decaldesk-file-warning');
						$li.append('<div class="decaldesk-file-parse-note decaldesk-file-parse-warning">⚠ ' + escapeHtml(parsed.warning) + '</div>');
					}
				}

				$ul.append($li);
			});
			$fileList.append($ul);

			$fileSummary.show();
			var countText = selectedFiles.length + ' / ' + MAX_FILES + ' files selected';
			if (invalidCount > 0) {
				countText += ' (' + invalidCount + ' with naming problems — see below)';
			}
			$fileCount.text(countText);
		}

		function escapeHtml(text) {
			return $('<div>').text(text).html();
		}

		// Бутон "Upload files"
		$uploadBtn.on('click', function (e) {
			e.preventDefault();

			if (!selectedFiles.length) {
				alert('Please select at least one file.');
				return;
			}

			var invalidFiles = selectedFiles.filter(function (file) {
				return !parseFilenameClientSide(file.name).ok;
			});
			if (invalidFiles.length > 0) {
				var proceed = confirm(
					invalidFiles.length + ' file(s) have a naming problem and will fail to upload:\n\n' +
					invalidFiles.map(function (f) { return '- ' + f.name; }).join('\n') +
					'\n\nUpload the other files anyway? (Cancel to go back and fix the names first.)'
				);
				if (!proceed) {
					return;
				}
			}

			var status = $('input[name="decaldesk_status"]:checked').val();
			var useVariants = false;
			var generateAllMockups = false;
			/*! <fs_premium_only> */
			useVariants = $('#decaldesk-use-variants').is(':checked');
			generateAllMockups = $('#decaldesk-generate-all-mockups').is(':checked');
			/*! </fs_premium_only> */

			function startUpload() {
				$results.empty();
				$summary.hide().empty();
				$progress.show();
				$progressBar.css('width', '0%');
				$uploadBtn.prop('disabled', true);

				var uploadStats = { queued: 0, failed: 0, total: selectedFiles.length };
				var jobRows = {}; // job_id -> { $row: jQuery, filename: string }

				uploadFilesSequentially(selectedFiles.slice(), status, useVariants, generateAllMockups, 0, uploadStats, jobRows);
			}

			/*! <fs_premium_only> */
			// Ако "Създай с избираеми варианти" е чекнато, записваме автоматично
			// каквото е въведено в полетата размери/материали/цветове ПРЕДИ да
			// започне качването - потребителят вече не трябва да помни отделен
			// клик на "Save" (по-рано: пропуснат "Save" => продуктът тихо се
			// създаваше БЕЗ варианти, без каквато и да е грешка).
			if (useVariants) {
				$uploadBtn.prop('disabled', true);
				saveVariantConfig().done(function (response) {
					$uploadBtn.prop('disabled', false);

					if (!response.success || !(response.data.sizes && response.data.sizes.length)) {
						alert('Add at least one width before uploading with variants (or uncheck "Create with selectable variants").');
						return;
					}

					startUpload();
				}).fail(function () {
					$uploadBtn.prop('disabled', false);
					alert('Could not save the variant configuration (server connection error). Please try again.');
				});
				return;
			}
			/*! </fs_premium_only> */

			startUpload();
		});

		/**
		 * Стъпка 1: качва файловете един по един (бързо - сървърът само валидира,
		 * записва файла и го слага в опашката, БЕЗ да чака AI/мокъп/продукт).
		 */
		function uploadFilesSequentially(files, status, useVariants, generateAllMockups, index, uploadStats, jobRows) {
			if (index >= files.length) {
				$progressLabel.text('All files uploaded - processing in the background...');

				if (Object.keys(jobRows).length > 0) {
					saveActiveJobsToStorage(jobRows, uploadStats);
					pollJobStatuses(jobRows, uploadStats);
				} else {
					// Нямаше нито един успешно качен файл - няма какво да следим
					$progress.hide();
					$uploadBtn.prop('disabled', false);
					showFinalSummary(uploadStats, { done: 0, error: 0 });
				}

				selectedFiles = [];
				renderFileList();
				return;
			}

			$progressLabel.text('Uploading file ' + (index + 1) + ' of ' + files.length + '...');

			var file = files[index];
			var formData = new FormData();
			formData.append('action', 'decaldesk_upload');
			formData.append('nonce', DecalDeskData.nonce);
			formData.append('status', status);
			formData.append('use_variants', useVariants ? '1' : '');
			formData.append('generate_all_mockups', generateAllMockups ? '1' : '');
			formData.append('file', file);

			$.ajax({
				url: DecalDeskData.ajax_url,
				type: 'POST',
				data: formData,
				processData: false,
				contentType: false,
				// Важно: изключваме глобалните jQuery Ajax събития (ajaxSend/ajaxComplete/...),
				// защото някои теми/плъгини имат глобални listener-и, които очакват
				// data да е низ (правят .indexOf() върху него) и се чупят при FormData заявки.
				global: false,
				success: function (response) {
					if (response.success && response.data && response.data.job_id) {
						uploadStats.queued++;
						var $row = createQueuedResultRow(file.name);
						jobRows[response.data.job_id] = { $row: $row, filename: file.name };
					} else {
						uploadStats.failed++;
						var message = (response.data && response.data.message) ? response.data.message : 'Unknown error during upload.';
						var editLink = (response.data && response.data.edit_link) ? response.data.edit_link : null;
						appendErrorRow(file.name, message, editLink);
					}
				},
				error: function () {
					uploadStats.failed++;
					appendErrorRow(file.name, 'A server connection error occurred.');
				},
				complete: function () {
					var percent = Math.round(((index + 1) / files.length) * 50); // качването е първата половина на прогреса
					$progressBar.css('width', percent + '%');
					uploadFilesSequentially(files, status, useVariants, generateAllMockups, index + 1, uploadStats, jobRows);
				}
			});
		}

		/**
		 * Стъпка 2: следи статуса на всички поставени в опашка job-ове, докато
		 * всички не станат 'done' или 'error'. Работи дори ако табът е бил
		 * презареден междувременно НЕ би продължил (jobRows е в паметта на
		 * тази сесия) - но самата обработка на сървъра продължава независимо
		 * от JS-а, така че продуктите се създават дори при затворен таб.
		 */
		function pollJobStatuses(jobRows, uploadStats) {
			var jobIds = Object.keys(jobRows);
			var totalJobs = jobIds.length;
			var pollCount = 0;
			var maxPolls = 300; // ~10 минути при интервал 2с - защитен таван

			var intervalId = setInterval(function () {
				pollCount++;

				$.post(DecalDeskData.ajax_url, {
					action: 'decaldesk_job_status',
					nonce: DecalDeskData.nonce,
					job_ids: jobIds.join(',')
				}).done(function (response) {
					if (!response.success) {
						return;
					}

					var jobs = response.data.jobs || [];
					var doneCount = 0;
					var errorCount = 0;
					var pendingCount = 0;

					jobs.forEach(function (job) {
						var entry = jobRows[job.id];
						if (!entry) {
							return;
						}

						if ('done' === job.status) {
							doneCount++;
							updateResultRow(entry.$row, entry.filename, job, true);
						} else if ('error' === job.status) {
							errorCount++;
							updateResultRow(entry.$row, entry.filename, job, false);
						} else {
							pendingCount++;
							entry.$row.find('.decaldesk-job-status-text').text(
								'processing' === job.status ? 'Processing...' : 'Queued for processing...'
							);
						}
					});

					// Прогрес бар: втората половина (50-100%) следва напредъка на обработката
					var processedPercent = totalJobs > 0 ? Math.round(((doneCount + errorCount) / totalJobs) * 50) : 50;
					$progressBar.css('width', (50 + processedPercent) + '%');
					$progressLabel.text('Processed ' + (doneCount + errorCount) + ' of ' + totalJobs + ' (pending: ' + pendingCount + ')...');

					if (doneCount + errorCount >= totalJobs || pollCount >= maxPolls) {
						clearInterval(intervalId);
						clearActiveJobsFromStorage();
						$progress.hide();
						$uploadBtn.prop('disabled', false);

						if (pollCount >= maxPolls && doneCount + errorCount < totalJobs) {
							$progressLabel.text('');
							var $notice = $('<div class="decaldesk-result-row decaldesk-result-error"></div>')
								.text('Processing is taking unusually long. Products are still being created in the background - reload the page in a bit to see the final result.');
							$results.prepend($notice);
						}

						showFinalSummary(uploadStats, { done: doneCount, error: errorCount });
					}
				});
			}, 2000);
		}

		function createQueuedResultRow(filename) {
			var $row = $('<div class="decaldesk-result-row decaldesk-result-pending"></div>');
			$row.append('<strong>' + escapeHtml(filename) + ':</strong> ');
			$row.append('<span class="decaldesk-job-status-text">Queued for processing...</span>');
			$results.append($row);
			return $row;
		}

		function appendErrorRow(filename, message, editLink) {
			var $row = $('<div class="decaldesk-result-row decaldesk-result-error"></div>');
			$row.append('<strong>' + escapeHtml(filename) + ':</strong> ' + escapeHtml(message));
			if (editLink) {
				$row.append(' <a href="' + editLink + '" target="_blank">View product →</a>');
			}
			$results.append($row);
		}

		function updateResultRow($row, filename, job, isSuccess) {
			$row.removeClass('decaldesk-result-pending');
			$row.addClass(isSuccess ? 'decaldesk-result-success' : 'decaldesk-result-error');
			$row.empty();

			$row.append('<strong>' + escapeHtml(filename) + ':</strong> ' + escapeHtml(job.message || (isSuccess ? 'Done.' : 'Unknown error.')));

			if (isSuccess && job.ai_source) {
				var badges = {
					ai_free: { label: 'AI (free)', cls: 'decaldesk-badge-free' },
					ai_claude: { label: 'AI (Claude)', cls: 'decaldesk-badge-claude' },
					fallback: { label: 'Template', cls: 'decaldesk-badge-fallback' }
				};
				var badge = badges[job.ai_source];
				if (badge) {
					$row.append(' <span class="decaldesk-badge ' + badge.cls + '">' + badge.label + '</span>');
				}
			}

			if (isSuccess && job.edit_link) {
				$row.append(' <a href="' + job.edit_link + '" target="_blank">Edit product →</a>');
			}
		}

		function showFinalSummary(uploadStats, processStats) {
			var hasErrors = uploadStats.failed > 0 || processStats.error > 0;
			var cssClass = hasErrors ? 'decaldesk-summary-partial' : 'decaldesk-summary-success';
			var text = 'Done: ' + processStats.done + ' successful products out of ' + uploadStats.total;

			var problems = [];
			if (uploadStats.failed) {
				problems.push(uploadStats.failed + ' files were not uploaded');
			}
			if (processStats.error) {
				problems.push(processStats.error + ' failed to process');
			}
			if (problems.length) {
				text += ' (' + problems.join(', ') + ')';
			}

			$summary.attr('class', 'decaldesk-summary ' + cssClass).text(text).show();
		}
	});
})(jQuery);

