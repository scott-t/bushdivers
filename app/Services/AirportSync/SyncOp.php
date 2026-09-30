<?php

namespace App\Services\AirportSync;

/**
 * Operation types and decisions used in an airport sync changeset.
 *
 * An operation is a plain array (it lives in the cache between requests):
 *  id, type, requires_review, confidence, decision, candidate_id,
 *  airport (target airport snapshot), candidates (ambiguous only), incoming, distance_nm, references, note
 */
final class SyncOp
{
    public const UPDATE = 'update';             // identifier + location match
    public const RENAME = 'rename';             // location match, identifier differs
    public const PROMOTE = 'promote';           // location match on a third-party airport
    public const AMBIGUOUS = 'ambiguous';       // several airports at the incoming location
    public const RELOCATE = 'relocate';         // identifier match, but far away
    public const CREATE = 'create';             // nothing matches
    public const UNTAG = 'untag';               // airport missing from this sim
    public const UNTAG_REVIEW = 'untag_review'; // missing from this sim, but hub or in use

    public const APPLY = 'apply';
    public const NEW = 'new';
    public const IGNORE = 'ignore';

    public const OUTCOME_NONE = 'none';
    public const OUTCOME_MODIFY = 'modify';
    public const OUTCOME_CREATE = 'create';
    public const OUTCOME_UNTAG = 'untag';

    /** @return array<int, string> */
    public static function allowedDecisions(string $type): array
    {
        return match ($type) {
            self::UPDATE => [self::APPLY],
            self::CREATE, self::UNTAG, self::UNTAG_REVIEW => [self::APPLY, self::IGNORE],
            default => [self::APPLY, self::NEW, self::IGNORE],
        };
    }

    /**
     * What an operation will do given its current decision.
     *
     * @return array{kind: string, airport_id?: int, promote?: bool}
     */
    public static function outcome(array $op, bool $includeUntags = true): array
    {
        $decision = $op['decision'] ?? null;

        if ($decision === null || $decision === self::IGNORE) {
            return ['kind' => self::OUTCOME_NONE];
        }

        if ($decision === self::NEW) {
            return ['kind' => self::OUTCOME_CREATE];
        }

        return match ($op['type']) {
            self::CREATE => ['kind' => self::OUTCOME_CREATE],
            self::UNTAG, self::UNTAG_REVIEW => $includeUntags
                ? ['kind' => self::OUTCOME_UNTAG, 'airport_id' => (int) $op['airport']['id']]
                : ['kind' => self::OUTCOME_NONE],
            self::AMBIGUOUS => self::ambiguousOutcome($op),
            default => [
                'kind' => self::OUTCOME_MODIFY,
                'airport_id' => (int) $op['airport']['id'],
                'promote' => $op['type'] === self::PROMOTE,
            ],
        };
    }

    private static function ambiguousOutcome(array $op): array
    {
        $candidate = collect($op['candidates'] ?? [])->firstWhere('id', $op['candidate_id'] ?? null);

        if (! $candidate) {
            return ['kind' => self::OUTCOME_NONE];
        }

        return [
            'kind' => self::OUTCOME_MODIFY,
            'airport_id' => (int) $candidate['id'],
            'promote' => (bool) $candidate['is_thirdparty'],
        ];
    }

    public static function isUnresolved(array $op): bool
    {
        if (! ($op['requires_review'] ?? false)) {
            return false;
        }

        if (($op['decision'] ?? null) === null) {
            return true;
        }

        return $op['type'] === self::AMBIGUOUS
            && $op['decision'] === self::APPLY
            && self::ambiguousOutcome($op)['kind'] === self::OUTCOME_NONE;
    }
}
