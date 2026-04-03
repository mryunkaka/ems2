<?php
session_start();

require_once __DIR__ . '/../auth/auth_guard.php';
require_once __DIR__ . '/../assets/design/ui/icon.php';

$pageTitle = 'Tesseract Test';

include __DIR__ . '/../partials/header.php';
include __DIR__ . '/../partials/sidebar.php';
?>

<section class="content">
    <div class="page page-shell">
        <h1 class="page-title">Tesseract OCR Test</h1>
        <p class="page-subtitle">Halaman uji terpisah untuk mencoba OCR berbasis Tesseract.js langsung di browser.</p>

        <div class="card card-section">
            <div class="card-header">Status</div>
            <div class="card-body">
                <div class="notice-box notice-warning" style="display:block;">
                    <strong>Catatan lingkungan</strong><br><br>
                    Binary <code>tesseract</code> tidak ditemukan di mesin ini, jadi halaman ini memakai <strong>Tesseract.js</strong> di browser, bukan executable Tesseract lokal.
                </div>
            </div>
        </div>

        <div class="grid gap-4 lg:grid-cols-[380px_minmax(0,1fr)]">
            <div class="card card-section mb-0">
                <div class="card-header">Input Gambar</div>
                <div class="card-body">
                    <label for="ocrImageInput" class="btn-secondary inline-flex items-center gap-2">
                        <?= ems_icon('arrow-up-tray', 'h-4 w-4') ?>
                        <span>Pilih Gambar</span>
                    </label>
                    <input type="file" id="ocrImageInput" accept="image/png,image/jpeg,image/webp" hidden>

                    <div class="mt-4">
                        <label class="form-label">Bahasa OCR</label>
                        <select id="ocrLanguage" class="form-input">
                            <option value="eng" selected>English (`eng`)</option>
                            <option value="ind">Indonesia (`ind`)</option>
                        </select>
                    </div>

                    <div class="mt-4 flex flex-wrap gap-3">
                        <button type="button" id="runOcrBtn" class="btn-primary" disabled>
                            <?= ems_icon('document-text', 'h-4 w-4') ?> Jalankan OCR
                        </button>
                        <button type="button" id="resetOcrBtn" class="btn-secondary">
                            <?= ems_icon('arrow-path', 'h-4 w-4') ?> Reset
                        </button>
                    </div>

                    <div id="ocrStatusBox" class="notice-box notice-info mt-4" style="display:none;"></div>

                    <div class="mt-4">
                        <div class="meta-text-xs mb-2">Preview</div>
                        <div class="rounded-xl border border-slate-200 bg-slate-50 p-3 min-h-28 flex items-center justify-center">
                            <img id="ocrPreviewImage" src="" alt="Preview OCR" class="max-h-48 hidden rounded-lg object-contain">
                            <span id="ocrPreviewEmpty" class="text-slate-400 text-sm">Belum ada gambar dipilih</span>
                        </div>
                    </div>
                </div>
            </div>

            <div class="card card-section mb-0">
                <div class="card-header">Hasil OCR</div>
                <div class="card-body">
                    <div class="grid gap-3 md:grid-cols-3">
                        <div class="rounded-xl border border-slate-200 bg-slate-50 px-4 py-3">
                            <div class="meta-text-xs">Confidence</div>
                            <div id="ocrConfidenceValue" class="font-semibold text-slate-900">-</div>
                        </div>
                        <div class="rounded-xl border border-slate-200 bg-slate-50 px-4 py-3">
                            <div class="meta-text-xs">Baris Terdeteksi</div>
                            <div id="ocrLinesValue" class="font-semibold text-slate-900">-</div>
                        </div>
                        <div class="rounded-xl border border-slate-200 bg-slate-50 px-4 py-3">
                            <div class="meta-text-xs">Status</div>
                            <div id="ocrRunState" class="font-semibold text-slate-900">Menunggu gambar</div>
                        </div>
                    </div>

                    <div class="mt-4">
                        <label class="form-label">Hasil Parse Otomatis</label>
                        <div class="grid gap-3 md:grid-cols-2">
                            <div class="rounded-xl border border-slate-200 bg-slate-50 px-4 py-3">
                                <div class="meta-text-xs">Last Name</div>
                                <div id="parsedLastName" class="font-semibold text-slate-900">-</div>
                            </div>
                            <div class="rounded-xl border border-slate-200 bg-slate-50 px-4 py-3">
                                <div class="meta-text-xs">First Name</div>
                                <div id="parsedFirstName" class="font-semibold text-slate-900">-</div>
                            </div>
                            <div class="rounded-xl border border-slate-200 bg-slate-50 px-4 py-3">
                                <div class="meta-text-xs">Date of Birth</div>
                                <div id="parsedDob" class="font-semibold text-slate-900">-</div>
                            </div>
                            <div class="rounded-xl border border-slate-200 bg-slate-50 px-4 py-3">
                                <div class="meta-text-xs">Sex</div>
                                <div id="parsedSex" class="font-semibold text-slate-900">-</div>
                            </div>
                            <div class="rounded-xl border border-slate-200 bg-slate-50 px-4 py-3">
                                <div class="meta-text-xs">Nationality</div>
                                <div id="parsedNationality" class="font-semibold text-slate-900">-</div>
                            </div>
                            <div class="rounded-xl border border-emerald-200 bg-emerald-50 px-4 py-3">
                                <div class="meta-text-xs text-emerald-700">Citizen ID</div>
                                <div id="parsedCitizenId" class="font-semibold text-emerald-700">-</div>
                            </div>
                        </div>
                    </div>

                    <div class="mt-4">
                        <label class="form-label">Teks Hasil</label>
                        <textarea id="ocrResultText" class="form-input min-h-[500px]" placeholder="Hasil OCR akan muncul di sini..." spellcheck="false"></textarea>
                    </div>
                </div>
            </div>
        </div>
    </div>
</section>

<script src="https://cdn.jsdelivr.net/npm/tesseract.js@5/dist/tesseract.min.js"></script>
<script>
    (function() {
        const imageInput = document.getElementById('ocrImageInput');
        const previewImage = document.getElementById('ocrPreviewImage');
        const previewEmpty = document.getElementById('ocrPreviewEmpty');
        const runBtn = document.getElementById('runOcrBtn');
        const resetBtn = document.getElementById('resetOcrBtn');
        const resultText = document.getElementById('ocrResultText');
        const statusBox = document.getElementById('ocrStatusBox');
        const languageSelect = document.getElementById('ocrLanguage');
        const confidenceValue = document.getElementById('ocrConfidenceValue');
        const linesValue = document.getElementById('ocrLinesValue');
        const runState = document.getElementById('ocrRunState');
        const parsedLastName = document.getElementById('parsedLastName');
        const parsedFirstName = document.getElementById('parsedFirstName');
        const parsedDob = document.getElementById('parsedDob');
        const parsedSex = document.getElementById('parsedSex');
        const parsedNationality = document.getElementById('parsedNationality');
        const parsedCitizenId = document.getElementById('parsedCitizenId');

        let imageDataUrl = '';
        let loadedImage = null;

        function setParsedValues(data) {
            parsedLastName.textContent = data.last_name || '-';
            parsedFirstName.textContent = data.first_name || '-';
            parsedDob.textContent = data.dob || '-';
            parsedSex.textContent = data.sex || '-';
            parsedNationality.textContent = data.nationality || '-';
            parsedCitizenId.textContent = data.citizen_id || '-';
        }

        function cleanupLine(line) {
            return String(line || '')
                .replace(/[|]/g, 'I')
                .replace(/[“”"]/g, '')
                .replace(/\s+/g, ' ')
                .trim();
        }

        function titleCaseWords(value) {
            return String(value || '')
                .toLowerCase()
                .replace(/\b[a-z]/g, function(char) {
                    return char.toUpperCase();
                });
        }

        function normalizeNameValue(value) {
            return titleCaseWords(
                String(value || '')
                    .toUpperCase()
                    .replace(/[^A-Z\s]/g, ' ')
                    .replace(/\s+/g, ' ')
                    .trim()
            );
        }

        function normalizeDobValue(value) {
            const sanitized = String(value || '')
                .replace(/[O]/gi, '0')
                .replace(/[Il]/g, '1');
            const match = sanitized.match(/(\d{4})\D?(\d{1,2})\D?(\d{1,2})/);
            if (!match) return '';

            const year = match[1];
            const month = String(match[2]).padStart(2, '0');
            const day = String(match[3]).padStart(2, '0');
            return `${year}-${month}-${day}`;
        }

        function normalizeSexValue(value) {
            const raw = String(value || '').toUpperCase();
            if (raw.includes('FEMALE')) return 'FEMALE';
            if (raw.includes('MALE')) return 'MALE';
            if (raw.includes('FE MALE') || raw.includes('FEMA') || raw.includes('FEMLE')) return 'FEMALE';
            if (raw.includes('MAE') || raw.includes('MAL') || raw.includes('MLE')) return 'MALE';
            if (raw === 'F') return 'FEMALE';
            if (raw === 'M') return 'MALE';
            return '';
        }

        function normalizeNationalityValue(value) {
            const raw = String(value || '').toUpperCase().replace(/[^A-Z]/g, '');
            if (!raw) return '';
            if (raw.includes('INDONESIA') || raw.includes('IDOONESIA') || raw.includes('INDONESIA')) {
                return 'INDONESIA';
            }
            if (raw.includes('JAPAN')) {
                return 'JAPAN';
            }
            return raw;
        }

        function normalizeCitizenIdValue(value) {
            let raw = String(value || '')
                .toUpperCase()
                .replace(/[^A-Z0-9]/g, '');

            if (!raw) return '';

            if (raw.startsWith('CITIZENID')) {
                raw = raw.replace(/^CITIZENID/, '');
            }

            raw = raw
                .replace(/O/g, '0')
                .replace(/I/g, '1')
                .replace(/L/g, '1');

            if (/^[A-Z][0-9].*[A-Z]$/.test(raw)) {
                raw = raw.slice(0, -1) + '6';
            }

            return raw;
        }

        function levenshteinDistance(a, b) {
            const left = String(a || '');
            const right = String(b || '');
            if (left === right) return 0;
            if (!left.length) return right.length;
            if (!right.length) return left.length;

            const matrix = Array.from({
                length: right.length + 1
            }, () => []);

            for (let i = 0; i <= right.length; i++) matrix[i][0] = i;
            for (let j = 0; j <= left.length; j++) matrix[0][j] = j;

            for (let i = 1; i <= right.length; i++) {
                for (let j = 1; j <= left.length; j++) {
                    const cost = right.charAt(i - 1) === left.charAt(j - 1) ? 0 : 1;
                    matrix[i][j] = Math.min(
                        matrix[i - 1][j] + 1,
                        matrix[i][j - 1] + 1,
                        matrix[i - 1][j - 1] + cost
                    );
                }
            }

            return matrix[right.length][left.length];
        }

        function looksLikeSameName(left, right) {
            const a = normalizeNameValue(left).replace(/\s+/g, '');
            const b = normalizeNameValue(right).replace(/\s+/g, '');
            if (!a || !b) return false;
            return levenshteinDistance(a, b) <= 1;
        }

        function chooseBestNameCandidate(candidates, avoidValue) {
            const cleaned = candidates
                .map(normalizeNameValue)
                .filter(Boolean)
                .filter(function(value, index, arr) {
                    return arr.indexOf(value) === index;
                })
                .filter(function(value) {
                    return !avoidValue || !looksLikeSameName(value, avoidValue);
                })
                .sort(function(a, b) {
                    return b.length - a.length;
                });

            return cleaned[0] || '';
        }

        function extractGenericDate(text) {
            return normalizeDobValue(String(text || '').match(/20\d{2}\D?\d{1,2}\D?\d{1,2}/)?.[0] || '');
        }

        function cropImageArea(image, rect, thresholdProfile) {
            const canvas = document.createElement('canvas');
            const sx = Math.max(0, Math.floor(image.width * rect.x));
            const sy = Math.max(0, Math.floor(image.height * rect.y));
            const sw = Math.max(1, Math.floor(image.width * rect.w));
            const sh = Math.max(1, Math.floor(image.height * rect.h));

            canvas.width = sw * 3;
            canvas.height = sh * 3;

            const ctx = canvas.getContext('2d', {
                willReadFrequently: true
            });
            ctx.drawImage(image, sx, sy, sw, sh, 0, 0, canvas.width, canvas.height);

            const imageData = ctx.getImageData(0, 0, canvas.width, canvas.height);
            const data = imageData.data;
            const high = thresholdProfile && thresholdProfile.high ? thresholdProfile.high : 165;
            const low = thresholdProfile && thresholdProfile.low ? thresholdProfile.low : 120;

            for (let i = 0; i < data.length; i += 4) {
                const gray = Math.round((data[i] * 0.299) + (data[i + 1] * 0.587) + (data[i + 2] * 0.114));
                const value = gray > high ? 255 : gray < low ? 0 : gray;
                data[i] = value;
                data[i + 1] = value;
                data[i + 2] = value;
            }

            ctx.putImageData(imageData, 0, 0);
            return canvas.toDataURL('image/png');
        }

        async function recognizeField(image, rect, whitelist, psm, thresholdProfile) {
            const croppedDataUrl = cropImageArea(image, rect, thresholdProfile);
            const result = await Tesseract.recognize(
                croppedDataUrl,
                'eng',
                {
                    logger: function() {},
                    tessedit_pageseg_mode: psm || Tesseract.PSM.SINGLE_LINE,
                    tessedit_char_whitelist: whitelist || undefined,
                }
            );

            return String((result.data && result.data.text) || '').trim();
        }

        function extractFieldFromLines(lines, label, fallbackPattern) {
            const labelUpper = label.toUpperCase();

            for (let i = 0; i < lines.length; i++) {
                const upper = lines[i].toUpperCase();
                if (upper.includes(labelUpper)) {
                    const afterLabel = cleanupLine(lines[i].substring(upper.indexOf(labelUpper) + labelUpper.length))
                        .replace(/^[:.\-=\s]+/, '')
                        .trim();
                    if (afterLabel) {
                        return afterLabel;
                    }

                    const nextLine = cleanupLine(lines[i + 1] || '');
                    if (nextLine && !nextLine.toUpperCase().includes('NAME') && !nextLine.toUpperCase().includes('DOB')) {
                        return nextLine;
                    }
                }
            }

            if (fallbackPattern) {
                const joined = lines.join('\n');
                const match = joined.match(fallbackPattern);
                return match ? cleanupLine(match[1]) : '';
            }

            return '';
        }

        function parseIdentityText(text) {
            const lines = String(text || '')
                .split(/\r?\n/)
                .map(cleanupLine)
                .filter(Boolean);

            const lastNameRaw = extractFieldFromLines(lines, 'LAST NAME', /LAST\s*NAME[:.\-\s]+([A-Z\s]+)/i);
            const firstNameRaw = extractFieldFromLines(lines, 'FIRST NAME', /(?:FIRST|FST)\s*NAME[:!.\-\s]+([A-Z\s]+)/i);
            const dobRaw = extractFieldFromLines(lines, 'DOB', /DOB[:.\-\s]+([0-9OIl\-\s]+)/i);
            const sexRaw = extractFieldFromLines(lines, 'SEX', /SEX[:.\-\s]+([A-Z]+)/i);
            const nationalityRaw = extractFieldFromLines(lines, 'NATIONALITY', /NATIONALITY[:.\-\s]+([A-Z]+)/i);
            const citizenRaw = extractFieldFromLines(lines, 'CITIZEN ID', /CITIZEN\s*ID[:.\-\s]+([A-Z0-9]+)/i);

            const firstNameCandidate = firstNameRaw && /\b/.test(firstNameRaw) && firstNameRaw.includes(' ')
                ? firstNameRaw.split(' ').pop()
                : firstNameRaw;

            return {
                last_name: normalizeNameValue(lastNameRaw),
                first_name: normalizeNameValue(firstNameCandidate),
                dob: normalizeDobValue(dobRaw) || extractGenericDate(text),
                sex: normalizeSexValue(sexRaw),
                nationality: normalizeNationalityValue(nationalityRaw),
                citizen_id: normalizeCitizenIdValue(citizenRaw),
            };
        }

        async function recognizeFieldVariants(image, variants) {
            const results = [];
            for (const variant of variants) {
                const text = await recognizeField(
                    image,
                    variant.rect,
                    variant.whitelist,
                    variant.psm,
                    variant.threshold
                );
                results.push(text);
            }
            return results;
        }

        async function recognizeValueColumn(image) {
            const block = await recognizeField(
                image,
                { x: 0.74, y: 0.18, w: 0.23, h: 0.62 },
                'ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789-',
                Tesseract.PSM.SINGLE_BLOCK,
                { low: 95, high: 185 }
            );

            return String(block || '')
                .split(/\r?\n/)
                .map(cleanupLine)
                .map(function(line) {
                    return line.replace(/[^A-Za-z0-9\- ]/g, '').trim();
                })
                .filter(Boolean);
        }

        async function parseIdentityFromTemplate(image, fallbackParsed) {
            if (!image) {
                return fallbackParsed;
            }

            const fields = {
                last_name: [
                    { rect: { x: 0.81, y: 0.20, w: 0.15, h: 0.08 }, whitelist: 'ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz', psm: Tesseract.PSM.SINGLE_WORD },
                    { rect: { x: 0.80, y: 0.20, w: 0.16, h: 0.08 }, whitelist: 'ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz', psm: Tesseract.PSM.SINGLE_WORD },
                ],
                first_name: [
                    { rect: { x: 0.81, y: 0.29, w: 0.15, h: 0.08 }, whitelist: 'ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz', psm: Tesseract.PSM.SINGLE_WORD },
                    { rect: { x: 0.80, y: 0.29, w: 0.16, h: 0.08 }, whitelist: 'ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz', psm: Tesseract.PSM.SINGLE_WORD },
                ],
                dob: [
                    { rect: { x: 0.79, y: 0.39, w: 0.18, h: 0.08 }, whitelist: '0123456789-', psm: Tesseract.PSM.SINGLE_WORD, threshold: { low: 100, high: 175 } },
                    { rect: { x: 0.78, y: 0.39, w: 0.19, h: 0.08 }, whitelist: '0123456789-', psm: Tesseract.PSM.SINGLE_WORD, threshold: { low: 90, high: 180 } },
                ],
                sex: [
                    { rect: { x: 0.83, y: 0.48, w: 0.13, h: 0.08 }, whitelist: 'ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz', psm: Tesseract.PSM.SINGLE_WORD },
                    { rect: { x: 0.82, y: 0.48, w: 0.14, h: 0.08 }, whitelist: 'ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz', psm: Tesseract.PSM.SINGLE_WORD },
                ],
                nationality: [
                    { rect: { x: 0.76, y: 0.57, w: 0.20, h: 0.08 }, whitelist: 'ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz', psm: Tesseract.PSM.SINGLE_WORD },
                    { rect: { x: 0.75, y: 0.57, w: 0.21, h: 0.08 }, whitelist: 'ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz', psm: Tesseract.PSM.SINGLE_WORD },
                ],
                citizen_id: [
                    { rect: { x: 0.77, y: 0.70, w: 0.19, h: 0.09 }, whitelist: 'ABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789', psm: Tesseract.PSM.SINGLE_WORD, threshold: { low: 95, high: 180 } },
                    { rect: { x: 0.76, y: 0.70, w: 0.20, h: 0.09 }, whitelist: 'ABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789', psm: Tesseract.PSM.SINGLE_WORD, threshold: { low: 85, high: 185 } },
                ],
                signature: { rect: { x: 0.10, y: 0.88, w: 0.34, h: 0.08 }, whitelist: 'ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz ', psm: Tesseract.PSM.SINGLE_LINE, threshold: { low: 110, high: 185 } }
            };

            try {
                const [
                    lastNameCandidates,
                    firstNameCandidates,
                    dobCandidates,
                    sexCandidates,
                    nationalityCandidates,
                    citizenCandidates,
                    signatureRaw,
                    valueColumnLines
                ] = await Promise.all([
                    recognizeFieldVariants(image, fields.last_name),
                    recognizeFieldVariants(image, fields.first_name),
                    recognizeFieldVariants(image, fields.dob),
                    recognizeFieldVariants(image, fields.sex),
                    recognizeFieldVariants(image, fields.nationality),
                    recognizeFieldVariants(image, fields.citizen_id),
                    recognizeField(image, fields.signature.rect, fields.signature.whitelist, fields.signature.psm, fields.signature.threshold),
                    recognizeValueColumn(image)
                ]);

                const signatureParts = normalizeNameValue(signatureRaw).split(' ').filter(Boolean);
                const signatureLastName = signatureParts[0] || '';
                const signatureFirstName = signatureParts[1] || '';
                const normalizedLastName = chooseBestNameCandidate([
                    ...lastNameCandidates,
                    signatureLastName,
                    fallbackParsed.last_name,
                ]);
                let normalizedFirstName = chooseBestNameCandidate([
                    ...firstNameCandidates,
                    fallbackParsed.first_name,
                ], normalizedLastName);

                if (!normalizedFirstName && signatureFirstName) {
                    normalizedFirstName = normalizeNameValue(signatureFirstName);
                }

                if (fallbackParsed.first_name && signatureFirstName) {
                    const textFirst = normalizeNameValue(fallbackParsed.first_name);
                    const signFirst = normalizeNameValue(signatureFirstName);
                    if (textFirst.length >= 2 && signFirst.length >= 2) {
                        const merged = signFirst.charAt(0) + textFirst.slice(1);
                        if (!looksLikeSameName(merged, normalizedLastName)) {
                            normalizedFirstName = merged;
                        }
                    }
                }

                const normalizedDob = dobCandidates
                    .map(normalizeDobValue)
                    .find(Boolean) || fallbackParsed.dob;
                const normalizedSex = sexCandidates
                    .map(normalizeSexValue)
                    .find(Boolean) || fallbackParsed.sex;
                const normalizedNationality = nationalityCandidates
                    .map(normalizeNationalityValue)
                    .find(Boolean) || fallbackParsed.nationality;
                const normalizedCitizenId = citizenCandidates
                    .map(normalizeCitizenIdValue)
                    .find(Boolean) || fallbackParsed.citizen_id;

                const columnMapped = valueColumnLines.length >= 5 ? {
                    last_name: normalizeNameValue(valueColumnLines[0] || ''),
                    first_name: normalizeNameValue(valueColumnLines[1] || ''),
                    dob: normalizeDobValue(valueColumnLines[2] || ''),
                    sex: normalizeSexValue(valueColumnLines[3] || ''),
                    nationality: normalizeNationalityValue(valueColumnLines[4] || ''),
                    citizen_id: normalizeCitizenIdValue(valueColumnLines[5] || ''),
                } : null;

                return {
                    last_name: (columnMapped && columnMapped.last_name) || normalizedLastName || signatureLastName || fallbackParsed.last_name,
                    first_name: (columnMapped && columnMapped.first_name) || normalizedFirstName || fallbackParsed.first_name || signatureFirstName,
                    dob: (columnMapped && columnMapped.dob) || normalizedDob,
                    sex: (columnMapped && columnMapped.sex) || normalizedSex,
                    nationality: (columnMapped && columnMapped.nationality) || normalizedNationality,
                    citizen_id: (columnMapped && columnMapped.citizen_id) || normalizedCitizenId,
                };
            } catch (error) {
                console.error('Template OCR failed:', error);
                return fallbackParsed;
            }
        }

        function showStatus(message, variant) {
            if (!statusBox) return;
            statusBox.className = 'notice-box mt-4 ' + (
                variant === 'danger' ? 'notice-danger' :
                variant === 'warning' ? 'notice-warning' :
                'notice-info'
            );
            statusBox.style.display = 'block';
            statusBox.innerHTML = message;
        }

        function resetAll() {
            imageDataUrl = '';
            loadedImage = null;
            if (imageInput) imageInput.value = '';
            if (previewImage) {
                previewImage.src = '';
                previewImage.classList.add('hidden');
            }
            if (previewEmpty) previewEmpty.classList.remove('hidden');
            if (runBtn) runBtn.disabled = true;
            if (resultText) resultText.value = '';
            if (statusBox) {
                statusBox.style.display = 'none';
                statusBox.innerHTML = '';
            }
            if (confidenceValue) confidenceValue.textContent = '-';
            if (linesValue) linesValue.textContent = '-';
            if (runState) runState.textContent = 'Menunggu gambar';
            setParsedValues({
                last_name: '',
                first_name: '',
                dob: '',
                sex: '',
                nationality: '',
                citizen_id: '',
            });
        }

        imageInput.addEventListener('change', function() {
            const file = imageInput.files && imageInput.files[0];
            if (!file) {
                resetAll();
                return;
            }

            const reader = new FileReader();
            reader.onload = function(event) {
                imageDataUrl = String(event.target.result || '');
                previewImage.src = imageDataUrl;
                previewImage.classList.remove('hidden');
                previewEmpty.classList.add('hidden');
                runBtn.disabled = false;
                runState.textContent = 'Siap dijalankan';
                showStatus('Gambar siap diproses oleh Tesseract.js.', 'info');

                const img = new Image();
                img.onload = function() {
                    loadedImage = img;
                };
                img.src = imageDataUrl;
            };
            reader.readAsDataURL(file);
        });

        runBtn.addEventListener('click', async function() {
            if (!imageDataUrl) {
                showStatus('Pilih gambar terlebih dahulu.', 'warning');
                return;
            }

            runBtn.disabled = true;
            runState.textContent = 'Memproses OCR...';
            confidenceValue.textContent = '-';
            linesValue.textContent = '-';
            showStatus('OCR sedang berjalan. Untuk gambar besar bisa butuh beberapa detik.', 'info');

            try {
                const result = await Tesseract.recognize(
                    imageDataUrl,
                    languageSelect.value || 'eng',
                    {
                        logger: function(message) {
                            if (message && message.status) {
                                showStatus(
                                    'Status: <strong>' + message.status + '</strong>' +
                                    (typeof message.progress === 'number' ? ' (' + Math.round(message.progress * 100) + '%)' : ''),
                                    'info'
                                );
                            }
                        }
                    }
                );

                const text = (result.data && result.data.text) ? result.data.text.trim() : '';
                const confidence = (result.data && typeof result.data.confidence !== 'undefined')
                    ? Number(result.data.confidence).toFixed(2) + '%'
                    : '-';
                const lines = Array.isArray(result.data && result.data.lines)
                    ? result.data.lines.length
                    : 0;
                const parsedBase = parseIdentityText(text);
                const parsed = await parseIdentityFromTemplate(loadedImage, parsedBase);

                resultText.value = text;
                confidenceValue.textContent = confidence;
                linesValue.textContent = String(lines);
                setParsedValues(parsed);
                runState.textContent = 'Selesai';
                showStatus('OCR selesai diproses.', 'info');
            } catch (error) {
                console.error(error);
                runState.textContent = 'Gagal';
                showStatus('OCR gagal dijalankan: ' + (error && error.message ? error.message : 'Unknown error'), 'danger');
            } finally {
                runBtn.disabled = false;
            }
        });

        resetBtn.addEventListener('click', resetAll);
        resetAll();
    })();
</script>

<?php include __DIR__ . '/../partials/footer.php'; ?>
