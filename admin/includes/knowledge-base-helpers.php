<?php
/**
 * AI 知识库上传辅助函数
 */

if (!defined('FEISHU_TREASURE')) {
    die('Access denied');
}

require_once dirname(__DIR__, 2) . '/includes/knowledge-retrieval.php';

function knowledge_base_abs_path(string $relativePath): string {
    return dirname(__DIR__, 2) . '/' . ltrim($relativePath, '/');
}

function cleanup_knowledge_file(?string $relativePath): void {
    $relativePath = trim((string) $relativePath);
    if ($relativePath === '') {
        return;
    }

    $paths = json_decode($relativePath, true);
    if (is_array($paths)) {
        foreach ($paths as $path) {
            cleanup_knowledge_file(is_string($path) ? $path : '');
        }
        return;
    }

    $absolutePath = knowledge_base_abs_path($relativePath);
    if (is_file($absolutePath)) {
        @unlink($absolutePath);
    }
}

function normalize_knowledge_text(string $text): string {
    return knowledge_retrieval_normalize_text($text);
}

function convert_uploaded_text_to_utf8(string $text): string {
    if ($text === '') {
        return '';
    }

    $detectedEncoding = mb_detect_encoding($text, ['UTF-8', 'GB18030', 'GBK', 'BIG5', 'UTF-16LE', 'UTF-16BE'], true);
    if (!$detectedEncoding || strtoupper($detectedEncoding) === 'UTF-8') {
        return $text;
    }

    $converted = @mb_convert_encoding($text, 'UTF-8', $detectedEncoding);
    return $converted === false ? $text : $converted;
}

function extract_zip_entry_via_ziparchive(string $filepath, string $entryName): string {
    if (!class_exists('ZipArchive')) {
        return '';
    }

    $zip = new ZipArchive();
    if ($zip->open($filepath) !== true) {
        return '';
    }

    $content = $zip->getFromName($entryName);
    $zip->close();
    return $content === false ? '' : (string) $content;
}

function extract_zip_entry_via_php_fallback(string $filepath, string $entryName): string {
    $binary = @file_get_contents($filepath);
    if ($binary === false || strlen($binary) < 22) {
        return '';
    }

    $eocdSignature = "PK\x05\x06";
    $eocdOffset = strrpos(substr($binary, max(0, strlen($binary) - 65557)), $eocdSignature);
    if ($eocdOffset === false) {
        return '';
    }
    $eocdOffset += max(0, strlen($binary) - 65557);

    $eocd = unpack(
        'Vsignature/vdisk/vdiskStart/ventriesDisk/ventriesTotal/VcentralSize/VcentralOffset/vcommentLength',
        substr($binary, $eocdOffset, 22)
    );
    if (!$eocd || (int) ($eocd['signature'] ?? 0) !== 0x06054b50) {
        return '';
    }

    $centralOffset = (int) ($eocd['centralOffset'] ?? 0);
    $entriesTotal = (int) ($eocd['entriesTotal'] ?? 0);
    $cursor = $centralOffset;

    for ($index = 0; $index < $entriesTotal; $index++) {
        $header = unpack(
            'Vsignature/vversionMade/vversionNeeded/vflags/vcompression/vmodTime/vmodDate/Vcrc/VcompressedSize/VuncompressedSize/vnameLength/vextraLength/vcommentLength/vdiskStart/vinternalAttrs/VexternalAttrs/VlocalHeaderOffset',
            substr($binary, $cursor, 46)
        );

        if (!$header || (int) ($header['signature'] ?? 0) !== 0x02014b50) {
            return '';
        }

        $nameLength = (int) $header['nameLength'];
        $extraLength = (int) $header['extraLength'];
        $commentLength = (int) $header['commentLength'];
        $fileName = substr($binary, $cursor + 46, $nameLength);

        if ($fileName === $entryName) {
            $localOffset = (int) $header['localHeaderOffset'];
            $localHeader = unpack(
                'Vsignature/vversionNeeded/vflags/vcompression/vmodTime/vmodDate/Vcrc/VcompressedSize/VuncompressedSize/vnameLength/vextraLength',
                substr($binary, $localOffset, 30)
            );

            if (!$localHeader || (int) ($localHeader['signature'] ?? 0) !== 0x04034b50) {
                return '';
            }

            $dataOffset = $localOffset + 30 + (int) $localHeader['nameLength'] + (int) $localHeader['extraLength'];
            $compressedSize = (int) $header['compressedSize'];
            $compressedData = substr($binary, $dataOffset, $compressedSize);
            $compression = (int) $header['compression'];

            if ($compression === 0) {
                return $compressedData;
            }

            if ($compression === 8) {
                $inflated = @gzinflate($compressedData);
                if ($inflated !== false) {
                    return $inflated;
                }

                if (function_exists('inflate_init') && function_exists('inflate_add')) {
                    $context = @inflate_init(ZLIB_ENCODING_RAW);
                    if ($context !== false) {
                        $inflated = @inflate_add($context, $compressedData, ZLIB_FINISH);
                        if ($inflated !== false) {
                            return $inflated;
                        }
                    }
                }
            }

            return '';
        }

        $cursor += 46 + $nameLength + $extraLength + $commentLength;
    }

    return '';
}

function extract_zip_entry_contents(string $filepath, string $entryName): string {
    $content = extract_zip_entry_via_ziparchive($filepath, $entryName);
    if ($content !== '') {
        return $content;
    }

    return extract_zip_entry_via_php_fallback($filepath, $entryName);
}

function extract_docx_inline_text(DOMNode $scope, DOMXPath $xpath): string {
    $parts = [];
    $nodes = $xpath->query('.//w:t | .//w:tab | .//w:br | .//w:cr', $scope);
    if ($nodes === false) {
        return '';
    }

    foreach ($nodes as $node) {
        $localName = $node->localName;
        if ($localName === 't') {
            $parts[] = $node->textContent;
        } elseif ($localName === 'tab') {
            $parts[] = "\t";
        } else {
            $parts[] = "\n";
        }
    }

    return normalize_knowledge_text(implode('', $parts));
}

function extract_docx_text_from_xml(string $xmlContent): string {
    if ($xmlContent === '') {
        return '';
    }

    $dom = new DOMDocument();
    $loaded = @$dom->loadXML($xmlContent, LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING);
    if (!$loaded) {
        return '';
    }

    $xpath = new DOMXPath($dom);
    $xpath->registerNamespace('w', 'http://schemas.openxmlformats.org/wordprocessingml/2006/main');

    $blocks = [];
    $bodyChildren = $xpath->query('//w:body/*');
    if ($bodyChildren !== false) {
        foreach ($bodyChildren as $child) {
            if ($child->localName === 'p') {
                $paragraphText = extract_docx_inline_text($child, $xpath);
                if ($paragraphText !== '') {
                    $blocks[] = $paragraphText;
                }
                continue;
            }

            if ($child->localName === 'tbl') {
                $rows = [];
                $tableRows = $xpath->query('./w:tr', $child);
                if ($tableRows !== false) {
                    foreach ($tableRows as $row) {
                        $cells = [];
                        $tableCells = $xpath->query('./w:tc', $row);
                        if ($tableCells === false) {
                            continue;
                        }
                        foreach ($tableCells as $cell) {
                            $cellText = extract_docx_inline_text($cell, $xpath);
                            if ($cellText !== '') {
                                $cells[] = $cellText;
                            }
                        }
                        if (!empty($cells)) {
                            $rows[] = implode("\t", $cells);
                        }
                    }
                }

                if (!empty($rows)) {
                    $blocks[] = implode("\n", $rows);
                }
            }
        }
    }

    if (empty($blocks)) {
        $fallback = $xpath->query('//w:t');
        if ($fallback !== false) {
            foreach ($fallback as $node) {
                $value = normalize_knowledge_text($node->textContent ?? '');
                if ($value !== '') {
                    $blocks[] = $value;
                }
            }
        }
    }

    return normalize_knowledge_text(implode("\n\n", $blocks));
}

function extract_docx_content(string $filepath): string {
    if (!is_file($filepath)) {
        return '';
    }

    $xmlContent = extract_zip_entry_contents($filepath, 'word/document.xml');
    if ($xmlContent === '') {
        return '';
    }

    return extract_docx_text_from_xml($xmlContent);
}

function parse_uploaded_knowledge_file(string $filepath, string $originalName, string $extension): array {
    $extension = strtolower(trim($extension));

    if ($extension === 'txt' || $extension === 'md') {
        $rawContent = @file_get_contents($filepath);
        if ($rawContent === false) {
            throw new RuntimeException('文件内容读取失败');
        }

        $content = normalize_knowledge_text(convert_uploaded_text_to_utf8($rawContent));
        if ($content === '') {
            throw new RuntimeException('文件内容为空或无法读取');
        }

        return [
            'content' => $content,
            'file_type' => $extension === 'md' ? 'markdown' : 'text',
        ];
    }

    if ($extension === 'docx') {
        $content = extract_docx_content($filepath);
        if ($content === '') {
            throw new RuntimeException('DOCX 文本提取失败，请确认文件未损坏；如为旧版文档，请先另存为 .docx 后重新上传');
        }

        return [
            'content' => $content,
            'file_type' => 'word',
        ];
    }

    if ($extension === 'doc') {
        throw new RuntimeException('暂不支持旧版 .doc 直接解析，请先另存为 .docx 后上传');
    }

    throw new RuntimeException('不支持的文件格式');
}

function knowledge_base_parse_uploaded_files(array $files, array &$storedPaths): array {
    $parsedFiles = [];

    foreach ($files as $file) {
        if (!is_array($file) || (int) ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
            continue;
        }

        $originalName = (string) ($file['name'] ?? '');
        $relativeName = trim((string) ($file['relative_name'] ?? $originalName));
        $extension = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));
        if (!in_array($extension, ['txt', 'md', 'docx'], true)) {
            throw new RuntimeException('不支持的文件格式，请上传 TXT、MD 或 DOCX 文件');
        }

        if ((int) ($file['size'] ?? 0) > 50 * 1024 * 1024) {
            throw new RuntimeException('单个文件不能超过 50MB');
        }

        $uploadDir = dirname(__DIR__, 2) . '/uploads/knowledge/';
        if (!is_dir($uploadDir)) {
            mkdir($uploadDir, 0755, true);
        }

        $filename = uniqid('', true) . '.' . $extension;
        $absolutePath = $uploadDir . $filename;
        $relativePath = 'uploads/knowledge/' . $filename;
        if (!move_uploaded_file((string) ($file['tmp_name'] ?? ''), $absolutePath)) {
            throw new RuntimeException('文件上传失败');
        }

        $storedPaths[] = $relativePath;
        $parsed = parse_uploaded_knowledge_file($absolutePath, $originalName, $extension);
        $parsedFiles[] = [
            'content' => (string) ($parsed['content'] ?? ''),
            'file_type' => (string) ($parsed['file_type'] ?? 'markdown'),
            'original_name' => $relativeName !== '' ? $relativeName : $originalName,
        ];
    }

    return $parsedFiles;
}

function knowledge_base_uploaded_files_from_request(string $fieldName = 'knowledge_files'): array {
    $files = $_FILES[$fieldName] ?? null;
    if (!is_array($files) || !isset($files['name'])) {
        $legacy = $_FILES['knowledge_file'] ?? null;
        return is_array($legacy) && (int) ($legacy['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_OK ? [$legacy] : [];
    }

    if (!is_array($files['name'])) {
        return (int) ($files['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_OK ? [$files] : [];
    }

    $normalized = [];
    foreach ($files['name'] as $index => $name) {
        $normalized[] = [
            'name' => $name,
            'type' => $files['type'][$index] ?? '',
            'tmp_name' => $files['tmp_name'][$index] ?? '',
            'error' => $files['error'][$index] ?? UPLOAD_ERR_NO_FILE,
            'size' => $files['size'][$index] ?? 0,
            'relative_name' => $files['full_path'][$index] ?? $name,
        ];
    }

    return array_values(array_filter($normalized, static fn (array $file): bool => (int) ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE));
}

function knowledge_base_merge_sources(string $manualContent, array $parsedFiles): string {
    $manualContent = normalize_knowledge_text($manualContent);
    if ($manualContent !== '' && empty($parsedFiles)) {
        return $manualContent;
    }

    $blocks = [];
    if ($manualContent !== '') {
        $blocks[] = "# 手动输入内容\n\n" . $manualContent;
    }

    foreach ($parsedFiles as $parsedFile) {
        $fileName = trim((string) ($parsedFile['original_name'] ?? ''));
        $blocks[] = '# 文件：' . $fileName . "\n\n" . trim((string) ($parsedFile['content'] ?? ''));
    }

    return normalize_knowledge_text(implode("\n\n---\n\n", $blocks));
}

function knowledge_base_infer_name(array $uploadedFiles, string $manualContent): string {
    if (!empty($uploadedFiles)) {
        $firstName = pathinfo((string) ($uploadedFiles[0]['name'] ?? ''), PATHINFO_FILENAME);
        $firstName = trim($firstName);
        if (count($uploadedFiles) === 1) {
            return $firstName;
        }
        return $firstName !== '' ? $firstName . ' 等 ' . count($uploadedFiles) . ' 个文件' : '导入的 ' . count($uploadedFiles) . ' 个文件';
    }

    $lines = preg_split('/\R/u', $manualContent) ?: [];
    foreach ($lines as $line) {
        $candidate = trim((string) $line);
        if ($candidate === '') {
            continue;
        }
        $candidate = preg_replace('/^#{1,6}\s*/u', '', $candidate) ?? $candidate;
        $candidate = preg_replace('/^[-*+]\s+/u', '', $candidate) ?? $candidate;
        $candidate = trim(strip_tags($candidate));
        $candidate = trim($candidate, " \t\n\r\0\x0B#*_`>");
        if ($candidate !== '') {
            return mb_substr($candidate, 0, 60, 'UTF-8');
        }
    }

    return '';
}

function knowledge_base_file_type_from_sources(string $requestedType, string $manualContent, array $parsedFiles): string {
    if (empty($parsedFiles)) {
        return in_array($requestedType, ['markdown', 'word', 'text'], true) ? $requestedType : 'markdown';
    }
    if (trim($manualContent) !== '' || count($parsedFiles) > 1) {
        return 'markdown';
    }
    $fileType = (string) ($parsedFiles[0]['file_type'] ?? 'markdown');
    return in_array($fileType, ['markdown', 'word', 'text'], true) ? $fileType : 'markdown';
}

function knowledge_base_encode_file_paths(array $storedPaths): string {
    if (empty($storedPaths)) {
        return '';
    }
    if (count($storedPaths) === 1) {
        return (string) $storedPaths[0];
    }
    return (string) json_encode(array_values($storedPaths), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
}
