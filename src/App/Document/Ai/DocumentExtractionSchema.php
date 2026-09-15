<?php

declare(strict_types=1);

namespace App\Document\Ai;

/**
 * Jediný kanonický formát dat vytěžovaných z dokladů.
 */
final class DocumentExtractionSchema
{
    /** @return array<string,mixed> */
    public static function jsonSchema(): array
    {
        $nullableNumber = ['type' => ['number', 'null']];
        $nullableString = ['type' => ['string', 'null']];

        return [
            'type' => 'object',
            'additionalProperties' => false,
            'properties' => [
                'document_type' => [
                    'type' => 'string',
                    'enum' => ['unknown', 'fuel_receipt', 'charging_invoice', 'service_invoice', 'expense_receipt', 'insurance', 'inspection', 'registration', 'other'],
                ],
                'provider' => $nullableString,
                'document_date' => $nullableString,
                'currency' => ['type' => 'string'],
                'confidence' => ['type' => 'number', 'minimum' => 0, 'maximum' => 1],
                'energy_entries' => [
                    'type' => 'array',
                    'items' => [
                        'type' => 'object',
                        'additionalProperties' => false,
                        'properties' => [
                            'occurred_at' => ['type' => 'string'],
                            'entry_type' => ['type' => 'string', 'enum' => ['charging', 'fueling']],
                            'energy_type' => ['type' => 'string', 'enum' => ['electricity', 'petrol', 'diesel', 'lpg', 'cng']],
                            'quantity' => ['type' => 'number'],
                            'unit_price' => $nullableNumber,
                            'total_price' => $nullableNumber,
                            'odometer_km' => $nullableNumber,
                            'station' => $nullableString,
                            'note' => $nullableString,
                        ],
                        'required' => ['occurred_at', 'entry_type', 'energy_type', 'quantity', 'unit_price', 'total_price', 'odometer_km', 'station', 'note'],
                    ],
                ],
                'service_records' => [
                    'type' => 'array',
                    'items' => [
                        'type' => 'object',
                        'additionalProperties' => false,
                        'properties' => [
                            'serviced_at' => ['type' => 'string'],
                            'category' => ['type' => 'string'],
                            'title' => ['type' => 'string'],
                            'provider' => $nullableString,
                            'odometer_km' => $nullableNumber,
                            'cost' => $nullableNumber,
                            'note' => $nullableString,
                        ],
                        'required' => ['serviced_at', 'category', 'title', 'provider', 'odometer_km', 'cost', 'note'],
                    ],
                ],
                'expenses' => [
                    'type' => 'array',
                    'items' => [
                        'type' => 'object',
                        'additionalProperties' => false,
                        'properties' => [
                            'occurred_at' => ['type' => 'string'],
                            'category' => ['type' => 'string'],
                            'title' => ['type' => 'string'],
                            'amount' => ['type' => 'number'],
                            'odometer_km' => $nullableNumber,
                            'note' => $nullableString,
                        ],
                        'required' => ['occurred_at', 'category', 'title', 'amount', 'odometer_km', 'note'],
                    ],
                ],
            ],
            'required' => ['document_type', 'provider', 'document_date', 'currency', 'confidence', 'energy_entries', 'service_records', 'expenses'],
        ];
    }

    public static function prompt(): string
    {
        return 'Analyzuj přiložený doklad související s provozem vozidla. '
            . 'Rozpoznej typ dokumentu a vytěž pouze údaje, které jsou na dokladu skutečně uvedené. '
            . 'Nevymýšlej chybějící hodnoty. U tankování/nabíjení vrať jednotlivé transakce, u servisní faktury servisní záznam. '
            . 'Datum/čas používej ve formátu YYYY-MM-DD HH:MM:SS, datum dokumentu YYYY-MM-DD. '
            . 'Měna je typicky CZK/EUR. confidence je číslo 0 až 1.';
    }
}
