<?php
declare(strict_types=1);
namespace App\Admission;
final class AdmissionDenied extends \RuntimeException
{
    public function __construct(public readonly string $safeCode) { parent::__construct('Admission is closed or denied.', 503); }
}
