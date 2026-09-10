<?php

namespace App\Mail\Transport;

use Illuminate\Support\Facades\Log;
use Resend\Client as ResendClient;
use Symfony\Component\Mailer\Exception\TransportException;
use Symfony\Component\Mailer\SentMessage;
use Symfony\Component\Mailer\Transport\AbstractTransport;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;

class ResendTransport extends AbstractTransport
{
    protected ResendClient $resend;

    public function __construct(ResendClient $resend)
    {
        $this->resend = $resend;

        parent::__construct();
    }

    protected function doSend(SentMessage $message): void
    {
        $originalMessage = $message->getOriginalMessage();

        if (! $originalMessage instanceof Email) {
            throw new TransportException(
                sprintf('The "%s" transport only supports instances of "%s".', __CLASS__, Email::class)
            );
        }

        $envelope = $message->getEnvelope();
        $payload = $this->buildPayload($originalMessage, $envelope);

        try {
            $response = $this->resend->emails->send($payload);

            $message->setMessageId($response->id ?? '');

            Log::info('Resend email sent successfully', [
                'message_id' => $response->id ?? null,
                'from' => $payload['from'],
                'to' => $payload['to'],
                'subject' => $payload['subject'] ?? null,
            ]);
        } catch (\Exception $e) {
            Log::error('Resend email send failed', [
                'error' => $e->getMessage(),
                'from' => $payload['from'] ?? null,
                'to' => $payload['to'] ?? [],
                'subject' => $payload['subject'] ?? null,
            ]);

            throw new TransportException(
                'Failed to send email via Resend: '.$e->getMessage(),
                0,
                $e
            );
        }
    }

    private function buildPayload(Email $message, \Symfony\Component\Mailer\Envelope $envelope): array
    {
        $payload = [
            'from' => $envelope->getSender()->getAddress(),
            'to' => array_map(
                fn (Address $address) => $address->getAddress(),
                $envelope->getRecipients()
            ),
            'subject' => $message->getSubject() ?? '(No Subject)',
        ];

        if ($html = $message->getHtmlBody()) {
            $payload['html'] = is_resource($html) ? stream_get_contents($html) : (string) $html;
        }

        if ($text = $message->getTextBody()) {
            $payload['text'] = is_resource($text) ? stream_get_contents($text) : (string) $text;
        }

        if ($cc = $message->getCc()) {
            $payload['cc'] = array_map(fn (Address $a) => $a->getAddress(), $cc);
        }

        $toAddresses = $message->getTo()
            ? array_map(fn (Address $a) => $a->getAddress(), $message->getTo())
            : [];

        $ccAddresses = $message->getCc()
            ? array_map(fn (Address $a) => $a->getAddress(), $message->getCc())
            : [];

        $envelopeAddresses = array_map(
            fn (Address $a) => $a->getAddress(),
            $envelope->getRecipients()
        );

        $bccAddresses = array_values(array_diff($envelopeAddresses, $toAddresses, $ccAddresses));

        if (! empty($bccAddresses)) {
            $payload['bcc'] = $bccAddresses;
        }

        if ($replyTo = $message->getReplyTo()) {
            $firstReplyTo = reset($replyTo);
            if ($firstReplyTo instanceof Address) {
                $payload['reply_to'] = [$firstReplyTo->getAddress()];
            }
        }

        foreach ($message->getAttachments() as $attachment) {
            $body = $attachment->getBody();

            $payload['attachments'][] = [
                'filename' => $attachment->getFilename(),
                'content' => base64_encode(is_resource($body) ? stream_get_contents($body) : $body),
                'content_type' => $attachment->getMediaType().'/'.$attachment->getMediaSubtype(),
            ];
        }

        return $payload;
    }

    public function __toString(): string
    {
        return 'resend';
    }
}
