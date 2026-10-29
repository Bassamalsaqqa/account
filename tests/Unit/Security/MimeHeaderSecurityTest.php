<?php

declare(strict_types=1);

namespace Tests\Unit\Security;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Mime\Email;
use Symfony\Component\Mime\Exception\RfcComplianceException;
use Symfony\Component\Mime\Header\ParameterizedHeader;

final class MimeHeaderSecurityTest extends TestCase
{
    public static function invalidParameterNames(): array
    {
        return [
            'CRLF header injection' => ["filename\r\nBcc: injected@example.test"],
            'space' => ['file name'],
            'separator' => ['filename;'],
            'quote' => ['file"name'],
            'NUL' => ["file\0name"],
            'empty' => [''],
        ];
    }

    #[DataProvider('invalidParameterNames')]
    public function test_constructor_rejects_non_token_parameter_names(string $name): void
    {
        $this->expectException(RfcComplianceException::class);
        new ParameterizedHeader('Content-Disposition', 'attachment', [$name => 'document.pdf']);
    }

    #[DataProvider('invalidParameterNames')]
    public function test_single_parameter_mutation_rejects_non_token_names(string $name): void
    {
        $header = new ParameterizedHeader('Content-Disposition', 'attachment', ['filename' => 'safe.pdf']);
        try {
            $header->setParameter($name, 'document.pdf');
            $this->fail('Unsafe parameter name was accepted.');
        } catch (RfcComplianceException) {
            $this->assertSame(['filename' => 'safe.pdf'], $header->getParameters());
        }
    }

    #[DataProvider('invalidParameterNames')]
    public function test_bulk_mutation_rejects_unsafe_names_without_partial_replacement(string $name): void
    {
        $header = new ParameterizedHeader('Content-Disposition', 'attachment', ['filename' => 'safe.pdf']);
        try {
            $header->setParameters(['valid-name' => 'valid', $name => 'document.pdf']);
            $this->fail('Unsafe bulk parameter name was accepted.');
        } catch (RfcComplianceException) {
            $this->assertSame(['filename' => 'safe.pdf'], $header->getParameters());
        }
    }

    public function test_valid_quoted_parameter_values_are_escaped(): void
    {
        $header = new ParameterizedHeader('Content-Type', 'application/pdf', ['filename' => 'a"b\\c.pdf']);
        $this->assertStringContainsString('filename="a\\"b\\\\c.pdf"', $header->getBodyAsString());
    }

    public function test_bilingual_mail_and_pdf_attachment_survive_serialization_without_delivery(): void
    {
        $arabic = "\u{0641}\u{0627}\u{062A}\u{0648}\u{0631}\u{0629}";
        $mail = (new Email)->from('sender@example.test')->to('recipient@example.test')
            ->subject($arabic.' / Invoice')->text($arabic.' plain text')
            ->html('<p dir="rtl">'.$arabic.'</p>')->attach('%PDF-1.4 synthetic fixture', $arabic.'.pdf', 'application/pdf');
        $encoded = $mail->toString();
        $copy = unserialize(serialize($mail), ['allowed_classes' => true]);
        $this->assertInstanceOf(Email::class, $copy);
        $this->assertSame($mail->getSubject(), $copy->getSubject());
        $this->assertSame($mail->getHtmlBody(), $copy->getHtmlBody());
        $this->assertCount(1, $copy->getAttachments());
        $this->assertSame($mail->getAttachments()[0]->bodyToString(), $copy->getAttachments()[0]->bodyToString());
        $this->assertSame($mail->getAttachments()[0]->getFilename(), $copy->getAttachments()[0]->getFilename());
        $part = $mail->getBody();
        $body = $part->toString();
        $this->assertSame($body, unserialize(serialize($part), ['allowed_classes' => true])->toString());
        $this->assertStringContainsString('application/pdf', $encoded);
    }
}
