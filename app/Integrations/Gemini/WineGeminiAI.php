<?php

namespace App\Integrations\Gemini;

class WineGeminiAI extends BaseGeminiAI
{
    public function domain(): string
    {
        return 'wine';
    }

    protected function systemInstruction(): string
    {
        return <<<'TXT'
You are an expert sommelier writing structured notes for a personal wine journal (Nexus cellar).

Goals:
- Give the drinker a real mental map of nose and palate: specific fruits, florals, spice, oak, earth, smoke, herbs.
- Example quality for aroma_notes / taste_notes: "blackcurrant", "ripe plum", "violet", "smoky cedar", "graphite", "vanilla oak" — not "fruit-forward" alone.
- identity.style: grape varieties or blend description (e.g. "Pinotage / Cabernet / Malbec Cape blend").
- identity.category: colour/type (red, white, rose, sparkling, orange, fortified, dessert).
- narrative.interesting_facts: producer reputation, typical site character, vineyard practice, or style history.
  Forbidden: merely restating WO / region / country already visible on the label.
- Prefer tannin/acidity language for wine structure. Leave bitterness_ibu null.
- When inferring a classic regional profile, still fill dense aroma/taste lists and mark uncertainties honestly.
Never invent critic scores or fake awards.
TXT;
    }

    protected function buildUserPrompt(AnalysisInput $input): string
    {
        $extra = $input->extraContext ? "\nExtra context from the owner:\n{$input->extraContext}" : '';
        $photo = $input->hasImage()
            ? "\nA bottle/label photo is attached — read all label text (producer, wine name, vintage, region, blend clues)."
            : '';

        return <<<TXT
Write a detailed tasting notebook entry for this wine.

Manual fields:
{$this->formatFieldsBlock($input->fields)}
{$extra}{$photo}

Requirements:
- aroma_notes: what you smell first and second (5–10 concrete descriptors).
- taste_notes: what flavours dominate the palate (5–10 concrete descriptors).
- tasting_notes: 2–4 sentences that read like a good short tasting sheet.
- interesting_facts: 2–4 non-obvious facts (not "it is from [region already stated]").
- food_pairings: specific dishes, include regional matches when natural.
TXT;
    }
}
