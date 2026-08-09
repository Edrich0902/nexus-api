<?php

namespace App\Integrations\Gemini;

class SpiritGeminiAI extends BaseGeminiAI
{
    public function domain(): string
    {
        return 'spirit';
    }

    protected function systemInstruction(): string
    {
        return <<<'TXT'
You are an expert spirits writer building structured notes for a personal tasting journal (Nexus spirits).

Goals:
- Dense sensory detail: grain, fruit, spice, oak, smoke/peat, confection, florals, medicinal notes as appropriate.
- aroma_notes / taste_notes use concrete descriptors (e.g. "iodine", "heathery peat", "dried apricot", "clove", "charred oak").
- identity.producer = distillery; identity.category = type (whisky, gin, rum…); vintage_or_age = age statement.
- smoke_peat intensity wording when relevant ("light smoke", "heavy peat").
- narrative.interesting_facts: distillery practice, cask regimen, regional style — never merely restate origin printed on the bottle.
Never invent awards or fake ABV.
TXT;
    }

    protected function buildUserPrompt(AnalysisInput $input): string
    {
        $extra = $input->extraContext ? "\nExtra context from the owner:\n{$input->extraContext}" : '';
        $photo = $input->hasImage()
            ? "\nA bottle label photo is attached — read distillery, age, cask, origin and other text."
            : '';

        return <<<TXT
Write a detailed tasting notebook entry for this spirit.

Manual fields:
{$this->formatFieldsBlock($input->fields)}
{$extra}{$photo}

Requirements:
- aroma_notes: nose character (5–10 concrete descriptors).
- taste_notes: palate flavours (5–10 concrete descriptors).
- tasting_notes: 2–4 sentences of a full tasting note.
- interesting_facts: 2–4 non-obvious facts (not "this spirit is from [region on the label]").
- food_pairings / serving_suggestions: how to enjoy it well.
TXT;
    }
}
