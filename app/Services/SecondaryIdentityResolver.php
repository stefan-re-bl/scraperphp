<?php

namespace App\Services;

use App\Models\Lead;
use Illuminate\Support\Str;

class SecondaryIdentityResolver
{
    public function __construct(private readonly ContactNormalizer $normalizer) {}

    /** @return array{status: string, reasons: list<string>, confidence: float} */
    public function compare(Lead $lead, array $candidate, bool $registeredWebsite = false): array
    {
        $sameName = filled($candidate['name'] ?? null) && $this->text($lead->name) === $this->text($candidate['name']);
        $sameDomain = filled($candidate['website'] ?? null) && $this->normalizer->domain($candidate['website']) === $this->normalizer->domain($lead->website_url);
        $samePhone = filled($candidate['phone'] ?? null) && $this->normalizer->phone($candidate['phone'], $lead->country) !== null
            && $this->normalizer->phone($candidate['phone'], $lead->country) === $this->normalizer->phone($lead->phone, $lead->country);
        $sameCity = filled($candidate['city'] ?? null) && filled($lead->city) && $this->text($candidate['city']) === $this->text($lead->city);
        $reasons = [];
        if (filled($candidate['name'] ?? null) && filled($lead->name) && ! $sameName) {
            $reasons[] = 'El nombre publicado no coincide con la identidad registrada.';
        }
        if (filled($candidate['city'] ?? null) && filled($lead->city) && ! $sameCity) {
            $reasons[] = 'La localidad publicada corresponde a otra ubicación o sucursal.';
        }
        if (filled($candidate['address'] ?? null) && filled($lead->location)
            && preg_match('/\d/', $candidate['address']) && preg_match('/\d/', $lead->location)
            && ! str_contains($this->text($lead->location), $this->text($candidate['address']))
            && ! str_contains($this->text($candidate['address']), $this->text($lead->location))) {
            $reasons[] = 'La dirección física publicada contradice el domicilio registrado.';
        }
        if ($reasons !== []) {
            return ['status' => 'conflict', 'reasons' => $reasons, 'confidence' => 0];
        }
        if ($registeredWebsite && $this->sharedDomainIsAmbiguous($lead, $candidate)) {
            return ['status' => 'ambiguous', 'reasons' => ['El dominio pertenece a varias identidades y la página no distingue esta sucursal.'], 'confidence' => 0.3];
        }
        if ($sameName && ($samePhone || ($sameDomain && $sameCity))) {
            return ['status' => 'strong', 'reasons' => ['Nombre e identificadores comerciales compatibles.'], 'confidence' => 0.95];
        }
        if ($registeredWebsite && empty($candidate['name']) && empty($candidate['city']) && empty($candidate['address'])) {
            return ['status' => 'registered_url', 'reasons' => ['Página del dominio registrado; identidad estructurada no disponible.'], 'confidence' => 0.65];
        }
        if ($registeredWebsite && $sameName && ($sameDomain || empty($candidate['website']))) {
            if (filled($candidate['phone'] ?? null) && filled($lead->phone) && ! $samePhone) {
                return ['status' => 'ambiguous', 'reasons' => ['Teléfonos distintos; hace falta revisar la correspondencia de sucursal.'], 'confidence' => 0.4];
            }

            return ['status' => 'registered_url', 'reasons' => ['Nombre compatible en el dominio registrado; corroboración limitada.'], 'confidence' => 0.75];
        }

        return ['status' => 'ambiguous', 'reasons' => ['La fuente no permite identificar inequívocamente este negocio.'], 'confidence' => 0.3];
    }

    private function sharedDomainIsAmbiguous(Lead $lead, array $candidate): bool
    {
        $domain = $this->normalizer->domain($lead->website_url);
        if (! $domain) {
            return $lead->duplicate_status === 'possible_duplicate';
        }
        $others = Lead::where('id', '!=', $lead->id)->where(fn ($query) => $query->where('domain', $domain)->orWhere('website_url', 'like', '%'.$domain.'%'))
            ->get(['id', 'name', 'city', 'location', 'phone', 'domain', 'website_url'])->filter(fn (Lead $other): bool => ($other->domain ?: $this->normalizer->domain($other->website_url)) === $domain);
        foreach ($others as $other) {
            $different = false;
            $distinguished = false;
            foreach (['name', 'city', 'location' => 'address'] as $field => $candidateField) {
                if (is_int($field)) {
                    $field = $candidateField;
                }
                if (filled($lead->{$field}) && filled($other->{$field}) && $this->text($lead->{$field}) !== $this->text($other->{$field})) {
                    $different = true;
                    if (filled($candidate[$candidateField] ?? null)
                        && str_contains($this->text($lead->{$field}), $this->text($candidate[$candidateField]))
                        && ! str_contains($this->text($other->{$field}), $this->text($candidate[$candidateField]))) {
                        $distinguished = true;
                    }
                }
            }
            if ($different && ! $distinguished) {
                return true;
            }
        }

        return $lead->duplicate_status === 'possible_duplicate' && empty($candidate['name']) && empty($candidate['address']);
    }

    private function text(string $value): string
    {
        return Str::of(Str::ascii($value))->lower()->replaceMatches('/[^a-z0-9]+/', ' ')->squish()->toString();
    }
}
