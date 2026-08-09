<?php

namespace App\Integrations\Gemini;

class BeerGeminiAI extends BaseGeminiAI
{
    public function domain(): string
    {
        return 'beer';
    }

    protected function systemInstruction(): string
    {
        return <<<'TXT'
You are an expert beer writer building structured notes for a personal tasting log (Nexus beer).

Goals:
- Dense sensory detail: hops (citrus, pine, tropical), malt (biscuit, chocolate, caramel), yeast, roast, smoke.
- aroma_notes / taste_notes use concrete descriptors (e.g. "grapefruit pith", "pine resin", "roasted coffee", "banana ester").
- identity.producer = brewery; identity.style = style name (IPA, stout, lager…); bitterness_ibu when known.
- narrative.interesting_facts: process (dry hop, barrel age), style history, brewery signature — never just restate city/country on the label.
- Leave tannin null unless clearly useful. Prefer carbonation and mouthfeel for structure.
Never invent awards or numbers you cannot justify.
TXT;
    }

    protected function buildUserPrompt(AnalysisInput $input): string
    {
        $extra = $input->extraContext ? "\nExtra context from the owner:\n{$input->extraContext}" : '';
        $photo = $input->hasImage()
            ? "\nA can/bottle label photo is attached — read style, ABV, IBU, brewery and other text."
            : '';

        return <<<TXT
Write a detailed tasting notebook entry for this beer.

Manual fields:
{$this->formatFieldsBlock($input->fields)}
{$extra}{$photo}

Requirements:
- aroma_notes: hop, malt, yeast, roast character (5–10 concrete descriptors).
- taste_notes: dominant flavours on the palate (5–10 concrete descriptors).
- tasting_notes: 2–4 sentences of a full tasting note.
- interesting_facts: 2–4 non-obvious facts (not "brewed in [place on the label]").
- food_pairings: specific dishes that match bitterness, roast, or hop profile.
TXT;
    }
}
