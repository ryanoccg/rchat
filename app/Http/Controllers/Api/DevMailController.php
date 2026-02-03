<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\File;

class DevMailController extends Controller
{
    /**
     * List logged emails
     */
    public function index(Request $request)
    {
        // Only allow in local environment
        if (!app()->environment('local')) {
            return response()->json(['message' => 'Not available in this environment'], 403);
        }

        $logPath = storage_path('logs');
        $mailLogs = [];

        // Find mail log files
        $files = File::glob($logPath . '/mail*.log');
        rsort($files); // Most recent first

        foreach ($files as $file) {
            $content = File::get($file);
            $emails = $this->parseMailLog($content);
            $mailLogs = array_merge($mailLogs, $emails);
        }

        // Sort by date descending
        usort($mailLogs, fn($a, $b) => strtotime($b['date']) - strtotime($a['date']));

        // Paginate
        $page = $request->input('page', 1);
        $perPage = $request->input('per_page', 20);
        $total = count($mailLogs);
        $emails = array_slice($mailLogs, ($page - 1) * $perPage, $perPage);

        return response()->json([
            'emails' => $emails,
            'pagination' => [
                'current_page' => (int) $page,
                'per_page' => (int) $perPage,
                'total' => $total,
                'last_page' => ceil($total / $perPage),
            ],
        ]);
    }

    /**
     * Get a single email by index
     */
    public function show(Request $request, int $index)
    {
        if (!app()->environment('local')) {
            return response()->json(['message' => 'Not available in this environment'], 403);
        }

        $logPath = storage_path('logs');
        $mailLogs = [];

        $files = File::glob($logPath . '/mail*.log');
        rsort($files);

        foreach ($files as $file) {
            $content = File::get($file);
            $emails = $this->parseMailLog($content);
            $mailLogs = array_merge($mailLogs, $emails);
        }

        usort($mailLogs, fn($a, $b) => strtotime($b['date']) - strtotime($a['date']));

        if (!isset($mailLogs[$index])) {
            return response()->json(['message' => 'Email not found'], 404);
        }

        return response()->json([
            'email' => $mailLogs[$index],
        ]);
    }

    /**
     * Clear all mail logs
     */
    public function clear(Request $request)
    {
        if (!app()->environment('local')) {
            return response()->json(['message' => 'Not available in this environment'], 403);
        }

        $logPath = storage_path('logs');
        $files = File::glob($logPath . '/mail*.log');

        foreach ($files as $file) {
            File::delete($file);
        }

        return response()->json([
            'message' => 'Mail logs cleared',
            'deleted' => count($files),
        ]);
    }

    /**
     * Parse mail log content into individual emails
     */
    protected function parseMailLog(string $content): array
    {
        $emails = [];

        // Laravel JSON log format: [YYYY-MM-DD HH:MM:SS] local.INFO: {"message":"...","..."}
        // Also handle legacy SMTP format
        $jsonPattern = '/\[(\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2})\] \w+\.INFO: (.*?)(?=\[\d{4}-\d{2}-\d{2}|$)/s';
        $smtpPattern = '/\[(\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2})\] \w+\.DEBUG: (.*?)(?=\[\d{4}-\d{2}-\d{2}|$)/s';

        // Try JSON format first (Laravel Log channels)
        if (preg_match_all($jsonPattern, $content, $matches, PREG_SET_ORDER)) {
            foreach ($matches as $match) {
                $date = $match[1];
                $jsonStr = trim($match[2]);

                // Parse JSON log
                if (($data = json_decode($jsonStr, true)) && is_array($data)) {
                    $email = [
                        'date' => $date,
                        'raw' => $jsonStr,
                        'level' => 'info',
                        'message' => $data['message'] ?? '',
                        'email' => $data['email'] ?? null,
                        'company' => $data['company'] ?? null,
                        'role' => $data['role'] ?? null,
                        'subject' => $data['subject'] ?? null,
                        'inviter' => $data['inviter'] ?? null,
                        'accept_url' => $data['accept_url'] ?? null,
                        'expires_at' => $data['expires_at'] ?? null,
                        'template' => $data['template'] ?? null,
                        'error' => $data['error'] ?? null,
                    ];
                    $emails[] = $email;
                }
            }
        }

        // Also parse SMTP format if any (for compatibility with mail trapping)
        if (preg_match_all($smtpPattern, $content, $matches, PREG_SET_ORDER)) {
            foreach ($matches as $match) {
                $date = $match[1];
                $body = trim($match[2]);

                $email = [
                    'date' => $date,
                    'raw' => $body,
                    'level' => 'debug',
                    'message' => 'SMTP email captured',
                ];

                // Extract SMTP headers
                $email['subject'] = $this->extractHeader($body, 'Subject');
                $email['to'] = $this->extractHeader($body, 'To');
                $email['from'] = $this->extractHeader($body, 'From');
                $email['content_type'] = $this->extractHeader($body, 'Content-Type');

                // Try to extract HTML body
                if (preg_match('/Content-Type: text\/html.*?\n\n(.*?)(?=--|\z)/s', $body, $htmlMatch)) {
                    $email['html_body'] = trim($htmlMatch[1]);
                }

                // Try to extract plain text body
                if (preg_match('/Content-Type: text\/plain.*?\n\n(.*?)(?=--|\z)/s', $body, $textMatch)) {
                    $email['text_body'] = trim($textMatch[1]);
                }

                $emails[] = $email;
            }
        }

        return $emails;
    }

    /**
     * Extract a header value from email content
     */
    protected function extractHeader(string $content, string $header): ?string
    {
        if (preg_match('/' . preg_quote($header, '/') . ': (.+?)(\r?\n(?! )|$)/i', $content, $match)) {
            return trim($match[1]);
        }
        return null;
    }
}
