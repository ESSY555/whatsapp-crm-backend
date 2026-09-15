<?php

namespace App\Services;

use App\Models\Message;
use App\Models\WhatsAppConnection;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Thin client around the Meta WhatsApp Cloud API. Every call is made with the
 * connection's own access token, which never leaves the server.
 */
class WhatsAppCloudApi
{
    public function __construct(private ?string $baseUrl = null)
    {
        $this->baseUrl = rtrim($baseUrl ?? config('services.whatsapp.graph_url', 'https://graph.facebook.com/v20.0'), '/');
    }

    /**
     * Send an outbound message and return the WhatsApp message id.
     *
     * @throws WhatsAppApiException
     */
    public function send(WhatsAppConnection $connection, string $to, Message $message): string
    {
        $payload = $this->payloadFor($to, $message);

        try {
            $response = Http::withToken($connection->access_token_encrypted)
                ->acceptJson()
                ->timeout(20)
                ->post("{$this->baseUrl}/{$connection->phone_number_id}/messages", $payload)
                ->throw();
        } catch (RequestException $e) {
            $error = $e->response->json('error') ?? [];
            throw new WhatsAppApiException(
                $error['message'] ?? $e->getMessage(),
                (int) ($error['code'] ?? $e->response->status()),
                $e->response->status(),
            );
        }

        $id = $response->json('messages.0.id');
        if (!$id) {
            throw new WhatsAppApiException('WhatsApp API returned no message id.', 0, $response->status());
        }

        return $id;
    }

    /**
     * Verify a connection's credentials by reading the phone number record.
     */
    public function verify(WhatsAppConnection $connection): bool
    {
        $response = Http::withToken($connection->access_token_encrypted)
            ->acceptJson()
            ->timeout(15)
            ->get("{$this->baseUrl}/{$connection->phone_number_id}");

        return $response->successful();
    }

    private function payloadFor(string $to, Message $message): array
    {
        $template = $message->template;

        // Meta-approved templates are sent as template messages so they can be
        // delivered outside the 24h customer service window.
        if ($template && $template->whatsapp_template_id) {
            $variables = $message->metadata['template_variables'] ?? [];
            $parameters = [];
            foreach ($template->placeholders() as $name) {
                $parameters[] = ['type' => 'text', 'text' => (string) ($variables[$name] ?? '')];
            }

            return [
                'messaging_product' => 'whatsapp',
                'to' => $to,
                'type' => 'template',
                'template' => [
                    'name' => $template->whatsapp_template_id,
                    'language' => ['code' => $template->language ?: 'en'],
                    'components' => $parameters ? [['type' => 'body', 'parameters' => $parameters]] : [],
                ],
            ];
        }

        return [
            'messaging_product' => 'whatsapp',
            'recipient_type' => 'individual',
            'to' => $to,
            'type' => 'text',
            'text' => ['preview_url' => false, 'body' => (string) $message->body],
        ];
    }
}

class WhatsAppApiException extends RuntimeException
{
    public function __construct(string $message, int $apiCode = 0, public readonly int $httpStatus = 0)
    {
        parent::__construct($message, $apiCode);
    }

    /**
     * Auth/permission errors mean the stored credentials are bad, not the message.
     */
    public function isCredentialError(): bool
    {
        return in_array($this->httpStatus, [401, 403], true) || in_array($this->getCode(), [190, 10, 200], true);
    }
}
