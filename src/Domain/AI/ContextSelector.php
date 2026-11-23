<?php
declare(strict_types=1);
namespace PkStore\Domain\AI;

final class ContextSelector {
    private const STOP = [
        'what','is','the','a','an','of','in','on','for','do','you','have','does','can','i','me','my',
        'want','need','looking','tell','show','price','cost','much','how','this','that','and','or',
        'with','any','available','stock','get','buy','kya','hai','ka','ki','ke','mein','ko','aur',
        'ya','nahi','ap','aap','mujhe','chahiye','kitna','under','below','less','than','up','to',
        'maximum','budget','within','rs','pkr','rupees','size','tak','se','kam','andar',
        'suggest','recommend','good','best','please','find','search','product','products','item','items','something','anything','koi','acha','achi','achha','achhi','karain','karo'
    ];

    private const CAT = [
        'phone'=>'mobile-phones','mobile'=>'mobile-phones','smartphone'=>'mobile-phones',
        'laptop'=>'laptops','shoe'=>'footwear','shoes'=>'footwear','sneaker'=>'footwear',
        'shirt'=>'clothing','dress'=>'clothing','kurta'=>'clothing','kameez'=>'clothing',
        'headphone'=>'audio','headphones'=>'audio','earphone'=>'audio','watch'=>'watches',
        'ebook'=>'ebooks','pdf'=>'ebooks','course'=>'courses','software'=>'software','game'=>'games',
    ];

    public function extract(string $message): array {
        $clean = strtolower(preg_replace('/[^\p{L}\p{N}\s.,_-]/u', ' ', $message) ?? '');
        $tokens = preg_split('/\s+/', trim($clean), -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $keywords = [];

        foreach ($tokens as $token) {
            $numeric = preg_match('/^\d+$/', $token) === 1;
            if (in_array($token, self::STOP, true)) continue;
            if ($numeric ? strlen($token) < 1 : strlen($token) < 3) continue;
            $keywords[] = $token;
        }

        return array_slice(array_values(array_unique($keywords)), 0, 10);
    }

    public function extractBudget(string $message): ?float {
        $patterns = [
            '/(?:under|below|less\s+than|up\s+to|max(?:imum)?|budget(?:\s+of)?|within)\s*(?:rs\.?|pkr|₨)?\s*([0-9][0-9,]*(?:\.[0-9]{1,2})?)/iu',
            '/between\s*[0-9][0-9,]*(?:\.[0-9]{1,2})?\s*(?:and|to)\s*([0-9][0-9,]*(?:\.[0-9]{1,2})?)/iu',
            '/(?:rs\.?|pkr|₨)\s*([0-9][0-9,]*(?:\.[0-9]{1,2})?)/iu',
            '/([0-9][0-9,]*(?:\.[0-9]{1,2})?)\s*(?:rs\.?|pkr|rupees|rupee|₨)/iu',
            '/([0-9][0-9,]*(?:\.[0-9]{1,2})?)\s*(?:tak|ke\s+andar|se\s+kam)/iu',
        ];

        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $message, $match) !== 1) continue;
            $value = (float) str_replace(',', '', $match[1]);
            if ($value > 0 && $value <= 100000000) return $value;
        }

        if (preg_match('/\b([0-9]+(?:\.[0-9]+)?)\s*k\b/iu', $message, $match) === 1) {
            $value = (float) $match[1] * 1000;
            if ($value > 0 && $value <= 100000000) return $value;
        }

        return null;
    }

    public function extractSku(string $message): ?string {
        if (preg_match('/(?<![A-Z0-9])([A-Z0-9][A-Z0-9._-]{2,}[0-9][A-Z0-9._-]*)(?![A-Z0-9])/i', $message, $match) !== 1) {
            return null;
        }
        return strtoupper(trim($match[1]));
    }

    public function detectCategoryIntent(string $message): ?string {
        $lower = strtolower($message);
        foreach (self::CAT as $term => $slug) {
            if (preg_match('/\b' . preg_quote($term, '/') . '\b/u', $lower) === 1) return $slug;
        }
        return null;
    }
}
