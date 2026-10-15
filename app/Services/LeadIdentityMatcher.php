<?php

namespace App\Services;

use App\Models\Lead;
use Illuminate\Support\Str;

class LeadIdentityMatcher
{
    /** @param array<string, mixed> $data
     * @return array{status: string, rule: ?string, lead: ?Lead, candidates: array<int, int>}
     */
    public function match(array $data): array
    {
        if (filled($data['source_identifier'] ?? null)) {
            $external = Lead::where(fn ($q) => $q->where('source', $data['source'])->where('source_identifier', $data['source_identifier']))
                ->orWhereHas('sources', fn ($q) => $q->where('source', $data['source'])->where('source_identifier', $data['source_identifier']))->get();
            if ($external->isNotEmpty()) {
                return $this->result($external->count() === 1 ? 'strong' : 'possible', 'source_identifier', $external->first(), $external->modelKeys());
            }
        }
        $candidates = Lead::where(function ($query) use ($data): void {
            $query->whereRaw('0 = 1');
            foreach (['domain', 'email', 'phone'] as $field) {
                if (filled($data[$field] ?? null) && ($field !== 'phone' || app(ContactNormalizer::class)->hasComparablePhone($data[$field]))) {
                    $query->orWhere($field, $data[$field]);
                    if (in_array($field, ['email', 'phone'])) {
                        $query->orWhereHas('contacts', fn ($q) => $q->where($field, $data[$field]));
                    }
                }
            }
            if (app(ContactNormalizer::class)->hasComparablePhone($data['phone_original'] ?? null)) {
                $query->orWhere('phone_original', $data['phone_original'])->orWhereHas('contacts', fn ($q) => $q->where('phone_original', $data['phone_original']));
            }
            foreach (array_filter([$data['name'] ?? null, $data['contact_person'] ?? null]) as $name) {
                $query->orWhereRaw('lower(name) = ?', [mb_strtolower($name)])->orWhereRaw('lower(contact_person) = ?', [mb_strtolower($name)])
                    ->orWhereHas('contacts', fn ($q) => $q->whereRaw('lower(name) = ?', [mb_strtolower($name)]));
            }
        })->get();
        $strong = $candidates->filter(function (Lead $lead) use ($data): bool {
            if ($this->contradicts($lead, $data)) {
                return false;
            }
            $sameName = filled($data['name'] ?? null) && $this->identity($lead->name) === $this->identity($data['name']);
            $sameDomain = filled($data['domain'] ?? null) && $data['domain'] === $lead->domain;
            $sameEmail = filled($data['email'] ?? null) && $data['email'] === $lead->email;
            $sameLocation = filled($data['location'] ?? null) && $this->identity($data['location']) === $this->identity($lead->location ?? '');

            return ($sameName && ($sameDomain || $sameEmail || $sameLocation)) || ($sameDomain && $sameEmail);
        });
        if ($strong->count() === 1 && $candidates->reject(fn (Lead $lead): bool => $lead->is($strong->first()))
            ->every(fn (Lead $lead): bool => $this->contradicts($lead, $data))) {
            return $this->result('strong', 'corroborated_identity', $strong->first(), $strong->modelKeys());
        }

        $match = $candidates->first();
        $rule = null;
        if ($match) {
            $rule = filled($data['domain'] ?? null) && $match->domain === $data['domain'] ? 'shared_domain' : (filled($data['phone'] ?? null) && $match->phone === $data['phone'] ? 'shared_phone' : (filled($data['email'] ?? null) && $match->email === $data['email'] ? 'shared_email' : 'shared_identity'));
        }

        return $this->result($candidates->isEmpty() ? 'unique' : 'possible', $rule, $match, $candidates->modelKeys());
    }

    private function contradicts(Lead $lead, array $data): bool
    {
        // A shared inbox or website does not identify a physical branch.
        if (filled($data['source_identifier'] ?? null)) {
            $identifiers = $lead->sources()->where('source', $data['source'])->whereNotNull('source_identifier')->pluck('source_identifier')->all();
            if ($lead->source === $data['source'] && filled($lead->source_identifier)) {
                $identifiers[] = $lead->source_identifier;
            }
            if ($identifiers !== [] && ! in_array($data['source_identifier'], $identifiers, true)) {
                return true;
            }
        }
        foreach (['name', 'location', 'city'] as $field) {
            if (filled($lead->$field) && filled($data[$field] ?? null)
                && $this->identity($lead->$field) !== $this->identity($data[$field])) {
                return true;
            }
        }

        return false;
    }

    private function identity(string $value): string
    {
        return Str::of(Str::ascii($value))->lower()->squish()->toString();
    }

    /** @param array<int, int> $candidates */
    private function result(string $status, ?string $rule, ?Lead $lead, array $candidates): array
    {
        return compact('status', 'rule', 'lead', 'candidates');
    }
}
