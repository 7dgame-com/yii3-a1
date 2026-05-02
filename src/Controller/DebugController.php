<?php

declare(strict_types=1);

namespace App\Controller;

use App\Service\SnapshotDiagnosticsService;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\StreamFactoryInterface;

/**
 * Lightweight operational diagnostics.
 */
final class DebugController
{
    public function __construct(
        private readonly SnapshotDiagnosticsService $snapshotDiagnosticsService,
        private readonly ResponseFactoryInterface $responseFactory,
        private readonly StreamFactoryInterface $streamFactory,
    ) {
    }

    /**
     * GET /debug/snapshot
     */
    public function snapshot(ServerRequestInterface $request): ResponseInterface
    {
        $report = $this->snapshotDiagnosticsService->collect();
        $format = strtolower((string) ($request->getQueryParams()['format'] ?? 'html'));

        if ($format === 'json') {
            return $this->createJsonResponse($report);
        }

        return $this->createHtmlResponse($this->renderSnapshotReport($report));
    }

    /**
     * @param array<string, mixed> $report
     */
    private function renderSnapshotReport(array $report): string
    {
        $status = (string) ($report['status'] ?? 'unknown');
        $generatedAt = (string) ($report['generated_at'] ?? '');
        $schema = $report['checks']['snapshot_schema'] ?? [];
        $table = $report['checks']['snapshot_table'] ?? [];
        $summary = $report['summary'] ?? [];

        return '<!doctype html>'
            . '<html lang="en"><head><meta charset="utf-8">'
            . '<meta name="viewport" content="width=device-width, initial-scale=1">'
            . '<title>Snapshot Diagnostics</title>'
            . '<style>'
            . 'body{font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",sans-serif;margin:0;background:#f7f8fa;color:#1f2937;}'
            . 'main{max-width:1100px;margin:0 auto;padding:32px 20px;}'
            . 'h1{font-size:28px;margin:0 0 8px;}h2{font-size:18px;margin:28px 0 10px;}'
            . '.meta{color:#6b7280;margin-bottom:18px}.status{display:inline-block;padding:4px 10px;border-radius:6px;font-weight:700;text-transform:uppercase;}'
            . '.status-ok{background:#dcfce7;color:#166534}.status-warning{background:#fef3c7;color:#92400e}.status-error{background:#fee2e2;color:#991b1b}'
            . 'section{background:#fff;border:1px solid #e5e7eb;border-radius:8px;padding:18px;margin-top:16px;}'
            . 'table{width:100%;border-collapse:collapse;font-size:14px;}th,td{text-align:left;border-bottom:1px solid #e5e7eb;padding:8px;vertical-align:top;}'
            . 'code,pre{background:#f3f4f6;border-radius:6px;}code{padding:2px 5px;}pre{padding:12px;overflow:auto;}ul{margin:8px 0 0 20px;padding:0;}'
            . '</style></head><body><main>'
            . '<h1>Snapshot Diagnostics</h1>'
            . '<div class="meta">Generated at ' . $this->escape($generatedAt) . ' · '
            . '<a href="?format=json">JSON</a></div>'
            . '<p><span class="status status-' . $this->escape($status) . '">' . $this->escape($status) . '</span></p>'
            . $this->renderListSection('Probable Causes', $summary['probable_causes'] ?? [])
            . $this->renderListSection('Next Steps', $summary['next_steps'] ?? [])
            . $this->renderSchemaSection(is_array($schema) ? $schema : [])
            . $this->renderTableSection(is_array($table) ? $table : [])
            . '<section><h2>Runtime</h2><pre>' . $this->escape($this->encodeJson($report['runtime'] ?? [])) . '</pre></section>'
            . '</main></body></html>';
    }

    /**
     * @param array<int, string> $items
     */
    private function renderListSection(string $title, array $items): string
    {
        $html = '<section><h2>' . $this->escape($title) . '</h2><ul>';
        foreach ($items as $item) {
            $html .= '<li>' . $this->escape((string) $item) . '</li>';
        }

        return $html . '</ul></section>';
    }

    /**
     * @param array<string, mixed> $schema
     */
    private function renderSchemaSection(array $schema): string
    {
        if (($schema['status'] ?? null) === 'error' && isset($schema['error'])) {
            return '<section><h2>Snapshot Schema</h2><pre>'
                . $this->escape($this->encodeJson($schema['error']))
                . '</pre></section>';
        }

        $html = '<section><h2>Snapshot Schema</h2>';
        $html .= '<p>Missing model properties: <code>'
            . $this->escape(implode(', ', $schema['missing_model_properties'] ?? []))
            . '</code></p>';
        $html .= '<p>Extra model properties: <code>'
            . $this->escape(implode(', ', $schema['extra_model_properties'] ?? []))
            . '</code></p>';
        $html .= '<table><thead><tr><th>Column</th><th>Type</th><th>Nullable</th><th>Key</th><th>Extra</th></tr></thead><tbody>';

        foreach (($schema['table_column_details'] ?? []) as $column) {
            if (!is_array($column)) {
                continue;
            }

            $html .= '<tr><td><code>' . $this->escape((string) ($column['name'] ?? '')) . '</code></td>'
                . '<td>' . $this->escape((string) ($column['type'] ?? '')) . '</td>'
                . '<td>' . $this->escape(!empty($column['nullable']) ? 'yes' : 'no') . '</td>'
                . '<td>' . $this->escape((string) ($column['key'] ?? '')) . '</td>'
                . '<td>' . $this->escape((string) ($column['extra'] ?? '')) . '</td></tr>';
        }

        $html .= '</tbody></table>';
        $html .= '<h2>Snapshot Model Properties</h2><pre>'
            . $this->escape($this->encodeJson($schema['model_properties'] ?? []))
            . '</pre></section>';

        return $html;
    }

    /**
     * @param array<string, mixed> $table
     */
    private function renderTableSection(array $table): string
    {
        return '<section><h2>Snapshot Table Probe</h2><pre>'
            . $this->escape($this->encodeJson($table))
            . '</pre></section>';
    }

    /**
     * @param mixed $data
     */
    private function createJsonResponse(mixed $data): ResponseInterface
    {
        $json = json_encode($data, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        $stream = $this->streamFactory->createStream($json);

        return $this->responseFactory->createResponse(200)
            ->withHeader('Content-Type', 'application/json')
            ->withBody($stream);
    }

    private function createHtmlResponse(string $html): ResponseInterface
    {
        $stream = $this->streamFactory->createStream($html);

        return $this->responseFactory->createResponse(200)
            ->withHeader('Content-Type', 'text/html; charset=UTF-8')
            ->withBody($stream);
    }

    /**
     * @param mixed $value
     */
    private function encodeJson(mixed $value): string
    {
        return json_encode($value, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    private function escape(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}
