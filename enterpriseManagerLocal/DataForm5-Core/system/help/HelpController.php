<?php
declare(strict_types=1);

namespace EasyIT\DataForm5\Help;

use Throwable;

final class HelpController
{
    public function __construct(
        private readonly HelpService $service
    ) {
    }

    public function json(string $helpId): void
    {
        try {
            $data = $this->service->getHelp($helpId);

            http_response_code(200);
            header('Content-Type: application/json; charset=utf-8');
            header('Cache-Control: no-store');

            echo json_encode(
                $data,
                JSON_UNESCAPED_UNICODE
                | JSON_UNESCAPED_SLASHES
                | JSON_THROW_ON_ERROR
            );
        } catch (Throwable $e) {
            http_response_code(404);
            header('Content-Type: application/json; charset=utf-8');
            header('Cache-Control: no-store');

            echo json_encode([
                'success' => false,
                'error' => 'HELP_NOT_FOUND',
                'message' => $e->getMessage(),
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        }
    }

    public function document(string $helpId): void
    {
        try {
            $doc = $this->service->getDocument($helpId);
            $html = $doc['html'];

            // Wenn die Help-ID einen Anker besitzt, wird nach dem Laden
            // innerhalb des gestreamten HTML-Dokuments dorthin gescrollt.
            if ($doc['anchor']) {
                $anchor = json_encode(
                    $doc['anchor'],
                    JSON_UNESCAPED_UNICODE
                    | JSON_UNESCAPED_SLASHES
                    | JSON_HEX_TAG
                    | JSON_HEX_AMP
                    | JSON_HEX_APOS
                    | JSON_HEX_QUOT
                );

                $script = '<script>'
                    . 'window.addEventListener("DOMContentLoaded",function(){'
                    . 'var id=' . $anchor . ';'
                    . 'var el=document.getElementById(id);'
                    . 'if(el){el.scrollIntoView({block:"start"});}'
                    . '});'
                    . '</script>';

                $html = str_ireplace(
                    '</body>',
                    $script . '</body>',
                    $html
                );
            }

            http_response_code(200);
            header('Content-Type: text/html; charset=utf-8');
            header('Cache-Control: no-store');
            header("X-Content-Type-Options: nosniff");

            echo $html;
        } catch (Throwable $e) {
            http_response_code(404);
            header('Content-Type: text/plain; charset=utf-8');

            echo 'Hilfe nicht gefunden: ' . $e->getMessage();
        }
    }
}
