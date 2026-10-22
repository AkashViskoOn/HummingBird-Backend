<?php

namespace App\Services;

use App\Models\MessageModel;
use App\Models\UserModel;

class AiService
{
    private ?string $apiKey;
    private string $apiUrl = 'https://api.openai.com/v1/chat/completions';
    private string $model = 'gpt-4o-mini';

    public function __construct()
    {
        $this->apiKey = getenv('OPENAI_API_KEY') ?: ($_ENV['OPENAI_API_KEY'] ?? ($_SERVER['OPENAI_API_KEY'] ?? null));
        if (empty($this->apiKey)) {
            log_message('error', '[AiService] OPENAI_API_KEY is not set in environment (checked getenv, $_ENV, $_SERVER)');
        }
    }

    private function callOpenAI(string $prompt, int $maxRetries = 1, string $systemPrompt = ''): ?string
    {
        if (empty($this->apiKey)) {
            log_message('error', '[AiService::callOpenAI] Cannot call OpenAI: API key is empty');
            return null;
        }

        $messages = [];
        if (!empty($systemPrompt)) {
            $messages[] = ['role' => 'system', 'content' => $systemPrompt];
        }
        $messages[] = ['role' => 'user', 'content' => $prompt];

        $data = json_encode([
            'model' => $this->model,
            'messages' => $messages,
            'max_tokens' => 1024,
        ]);

        for ($attempt = 0; $attempt <= $maxRetries; $attempt++) {
            $ch = curl_init($this->apiUrl);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, $data);
            curl_setopt($ch, CURLOPT_HTTPHEADER, [
                'Content-Type: application/json',
                'Authorization: Bearer ' . $this->apiKey,
            ]);
            curl_setopt($ch, CURLOPT_TIMEOUT, 30);
            $caPath = $this->getCACertPath();
            if (!empty($caPath)) {
                curl_setopt($ch, CURLOPT_CAINFO, $caPath);
            }

            $response = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);

            if ($response === false) {
                $error = curl_error($ch);
                curl_close($ch);
                log_message('error', '[AiService::callOpenAI] cURL error (attempt ' . ($attempt + 1) . '): ' . $error);
                if ($attempt < $maxRetries) {
                    sleep(1);
                    continue;
                }
                return null;
            }

            curl_close($ch);

            if ($httpCode !== 200) {
                $responseBody = substr($response, 0, 1000);
                log_message('error', '[AiService::callOpenAI] HTTP ' . $httpCode . ': ' . $responseBody);
                if ($attempt < $maxRetries) {
                    sleep(1);
                    continue;
                }
                return null;
            }

            $decoded = json_decode($response, true);
            if (isset($decoded['choices'][0]['message']['content'])) {
                return $decoded['choices'][0]['message']['content'];
            }
            log_message('error', '[AiService::callOpenAI] Unexpected response structure: ' . substr($response, 0, 500));
            return null;
        }

        return null;
    }

    private function getCACertPath(): string
    {
        $candidates = [
            'C:/cacert.pem',
            'C:/php/extras/ssl/cacert.pem',
            'C:/xampp/php/extras/ssl/cacert.pem',
            'C:/wamp64/bin/php/php8.2.0/extras/ssl/cacert.pem',
            __DIR__ . '/../../cacert.pem',
            __DIR__ . '/../cacert.pem',
        ];
        foreach ($candidates as $path) {
            if (file_exists($path)) {
                return $path;
            }
        }
        $iniPath = ini_get('curl.cainfo');
        if (!empty($iniPath) && file_exists($iniPath)) {
            return $iniPath;
        }
        return '';
    }

    public function generateText(string $prompt): ?string
    {
        $systemPrompt = 'Generate a text message based on the user\'s prompt. Don\'t include any comments or suggestions. The message should be impressive and ready to send. Return only the message text.';
        $result = $this->callOpenAI($prompt, 1, $systemPrompt);
        if ($result !== null) {
            return trim($result);
        }
        return null;
    }

    public function getAiMessage(string $prompt): ?string
    {
        return $this->callOpenAI($prompt);
    }

    public function getReplySuggestions(string $message, array $conversationHistory = []): ?array
    {
        $historyContext = '';
        if (!empty($conversationHistory)) {
            $recent = array_slice($conversationHistory, -3);
            $historyContext = 'Conversation history: ' . implode(' | ', $recent) . "\n";
        }

        $prompt = $historyContext . 'Based on this message: "' . $message . '"\n'
            . 'Suggest 5 short, natural reply options a person could send. '
            . 'Return only the suggestions as a JSON array of strings, nothing else.';

        $result = $this->callOpenAI($prompt);
        if ($result !== null) {
            $decoded = json_decode($result, true);
            if (is_array($decoded)) {
                return array_slice($decoded, 0, 5);
            }
            $lines = explode("\n", trim($result));
            $suggestions = array_filter($lines, fn($l) => strlen(trim($l)) > 2);
            return array_slice(array_values($suggestions), 0, 5);
        }

        return $this->fallbackReplySuggestions($message);
    }

    public function categorizeMessage(string $message): ?array
    {
        $prompt = 'Categorize this message into one of these categories: urgent, work, promotional, transaction, social, personal. '
            . 'Message: "' . $message . '"\n'
            . 'Return only the category name as a JSON object: {"category": "..."}';

        $result = $this->callOpenAI($prompt);
        if ($result !== null) {
            $decoded = json_decode($result, true);
            if (isset($decoded['category'])) {
                return ['category' => $decoded['category']];
            }
        }

        return ['category' => $this->fallbackCategorize($message)];
    }

    public function generateSummary(array $messages, int $maxLength = 100): ?array
    {
        $textMessages = [];
        foreach ($messages as $msg) {
            if (is_string($msg)) {
                $textMessages[] = $msg;
            } elseif (is_array($msg) && isset($msg['message'])) {
                $textMessages[] = $msg['message'];
            } elseif (is_array($msg) && isset($msg['text'])) {
                $textMessages[] = $msg['text'];
            } elseif (is_array($msg) && isset($msg['content'])) {
                $textMessages[] = $msg['content'];
            }
        }

        if (empty($textMessages)) {
            return null;
        }

        $conversationText = implode("\n", $textMessages);
        $prompt = 'Summarize the following conversation in ' . $maxLength . ' characters or less. '
            . 'Return only the summary text, nothing else.\n\n' . $conversationText;

        $result = $this->callOpenAI($prompt);
        if ($result !== null) {
            $summary = trim($result);
            if (mb_strlen($summary) > $maxLength) {
                $summary = mb_substr($summary, 0, $maxLength);
                $lastSpace = mb_strrpos($summary, ' ');
                if ($lastSpace !== false) {
                    $summary = mb_substr($summary, 0, $lastSpace);
                }
                $summary .= '...';
            }
            return [
                'summary' => $summary,
                'original_length' => mb_strlen($summary),
                'message_count' => count($textMessages)
            ];
        }

        return $this->fallbackGenerateSummary($textMessages, $maxLength);
    }

    public function analyzeSentiment(string $message): ?array
    {
        $prompt = 'Analyze the sentiment of this message: "' . $message . '"\n'
            . 'Return a JSON object with: sentiment (positive/negative/neutral), score (0.0 to 1.0), and emoji. '
            . 'Example: {"sentiment": "positive", "score": 0.85, "emoji": "😊"}';

        $result = $this->callOpenAI($prompt);
        if ($result !== null) {
            $decoded = json_decode($result, true);
            if (isset($decoded['sentiment'], $decoded['score'])) {
                return [
                    'sentiment' => $decoded['sentiment'],
                    'score' => (float)$decoded['score'],
                    'emoji' => $decoded['emoji'] ?? ''
                ];
            }
        }

        return $this->fallbackAnalyzeSentiment($message);
    }

    public function smartSearch(string $query, string $userId): ?array
    {
        $result = $this->fallbackSmartSearch($query, $userId);
        return $result;
    }

    public function translateMessage(string $message, string $targetLanguage): ?array
    {
        $languageMap = [
            'es' => 'Spanish', 'spanish' => 'Spanish',
            'fr' => 'French', 'french' => 'French',
            'de' => 'German', 'german' => 'German',
            'it' => 'Italian', 'italian' => 'Italian',
            'pt' => 'Portuguese', 'portuguese' => 'Portuguese',
            'ru' => 'Russian', 'russian' => 'Russian',
            'ja' => 'Japanese', 'japanese' => 'Japanese',
            'ko' => 'Korean', 'korean' => 'Korean',
            'zh' => 'Chinese', 'chinese' => 'Chinese', 'mandarin' => 'Chinese',
            'ar' => 'Arabic', 'arabic' => 'Arabic',
            'hi' => 'Hindi', 'hindi' => 'Hindi',
            'bn' => 'Bengali', 'bengali' => 'Bengali',
            'pa' => 'Punjabi', 'punjabi' => 'Punjabi',
            'ta' => 'Tamil', 'tamil' => 'Tamil',
            'te' => 'Telugu', 'telugu' => 'Telugu',
            'mr' => 'Marathi', 'marathi' => 'Marathi',
            'gu' => 'Gujarati', 'gujarati' => 'Gujarati',
            'nl' => 'Dutch', 'dutch' => 'Dutch',
            'tr' => 'Turkish', 'turkish' => 'Turkish',
            'pl' => 'Polish', 'polish' => 'Polish',
            'sv' => 'Swedish', 'swedish' => 'Swedish',
            'da' => 'Danish', 'danish' => 'Danish',
            'fi' => 'Finnish', 'finnish' => 'Finnish',
            'no' => 'Norwegian', 'norwegian' => 'Norwegian',
            'cs' => 'Czech', 'czech' => 'Czech',
            'ro' => 'Romanian', 'romanian' => 'Romanian',
            'hu' => 'Hungarian', 'hungarian' => 'Hungarian',
            'el' => 'Greek', 'greek' => 'Greek',
            'he' => 'Hebrew', 'hebrew' => 'Hebrew',
            'th' => 'Thai', 'thai' => 'Thai',
            'vi' => 'Vietnamese', 'vietnamese' => 'Vietnamese',
            'id' => 'Indonesian', 'indonesian' => 'Indonesian',
            'ms' => 'Malay', 'malay' => 'Malay',
        ];

        $langCode = strtolower($targetLanguage);
        $langName = $languageMap[$langCode] ?? ucfirst($langCode);

        $prompt = 'Translate this message to ' . $langName . '. '
            . 'Return ONLY the translated text, nothing else. '
            . 'Message: "' . $message . '"';

        $result = $this->callOpenAI($prompt);
        if ($result !== null) {
            return [
                'translated_text' => trim($result),
                'source_language' => 'en',
                'target_language' => $langName,
                'is_mock' => false
            ];
        }

        return $this->fallbackTranslateMessage($message, $langName, $languageMap);
    }

    public function detectSpam(string $message, string $senderId = ''): ?array
    {
        $prompt = 'Analyze this message for spam. Message: "' . $message . '"\n'
            . 'Return a JSON object with: is_spam (boolean), confidence (0.0 to 1.0), '
            . 'and reason (short string explaining why). '
            . 'Example: {"is_spam": false, "confidence": 0.05, "reason": "Legitimate message"}';

        $result = $this->callOpenAI($prompt);
        if ($result !== null) {
            $decoded = json_decode($result, true);
            if (isset($decoded['is_spam'], $decoded['confidence'])) {
                return [
                    'is_spam' => (bool)$decoded['is_spam'],
                    'confidence' => (float)$decoded['confidence'],
                    'reason' => $decoded['reason'] ?? ''
                ];
            }
        }

        return $this->fallbackDetectSpam($message);
    }

    public function getChatbotResponse(string $message): ?array
    {
        $prompt = 'You are Humming Bird AI, a helpful messaging assistant. '
            . 'Respond to this user message in a friendly, concise way. '
            . 'Message: "' . $message . '"';

        $result = $this->callOpenAI($prompt);
        if ($result !== null) {
            return [
                'response' => trim($result),
                'user_message' => $message
            ];
        }

        return $this->fallbackChatbotResponse($message);
    }

    public function getNotificationGroup(array $notifications): ?array
    {
        return $this->fallbackNotificationGroup($notifications);
    }

    private function fallbackReplySuggestions(string $message): array
    {
        $lower = mb_strtolower($message);

        if (preg_match('/\?\s*$/', $message) || preg_match('/\b(who|what|where|when|why|how|which|whom|whose)\b/i', $message)) {
            return [
                'Let me check on that for you.',
                "I'll look into it and get back to you.",
                "That's a good question. Here's what I know...",
                'I can help you find the answer to that.',
                'Would you like me to search for more details?'
            ];
        }
        if (preg_match('/\b(hi|hello|hey|good\s*(morning|afternoon|evening)|greetings|howdy|what\'s\s*up)\b/i', $lower)) {
            return [
                'Hello! How can I help you today?',
                'Hi there! What can I do for you?',
                'Hey! Great to hear from you.',
                "Hello! How's your day going?"
            ];
        }
        if (preg_match('/\bthank(s| you)?\b/i', $lower)) {
            return [
                "You're welcome! Happy to help.",
                'Glad I could assist!',
                'Anytime! Let me know if you need anything else.',
                'My pleasure!',
                "You're most welcome!"
            ];
        }
        if (preg_match('/\bsorry\b/i', $lower)) {
            return [
                "No worries at all!",
                "It's okay, don't worry about it.",
                'I understand, no problem.',
                "That's perfectly fine.",
                "Apology accepted. Let's move forward."
            ];
        }
        if (preg_match('/\bmeeting|schedule|appointment|book|calendar\b/i', $lower)) {
            return [
                'Sure, let me check available slots.',
                'I can help schedule that. What time works for you?',
                'Would you prefer a virtual or in-person meeting?',
                'Let me send you a calendar invite.',
                'How about we meet tomorrow?'
            ];
        }
        if (preg_match('/\b(love|miss|dear|sweet|hug|kiss|care|heart|beautiful|gorgeous|amazing)\b/i', $lower)) {
            return [
                "That's so sweet of you! 💕",
                'You mean the world to me!',
                'I feel the same way! 😊',
                "You're amazing too!",
                'Sending you a big hug! 🤗'
            ];
        }

        return [
            "Thanks for your message!",
            "Got it! I'll take care of it.",
            'Sure, I\'ll handle that.',
            'Noted. Anything else I can help with?',
            'Okay, let me work on that.'
        ];
    }

    private function fallbackCategorize(string $message): string
    {
        $lower = mb_strtolower($message);

        if (preg_match('/\b(urgent|asap|immediately|emergency|critical|deadline\s*(today|now)|help\s*quick)\b/i', $lower)) {
            return 'urgent';
        }
        if (preg_match('/\b(meeting|deadline|project|report|presentation|task|assignment|office|work|colleague|boss|manager|client|invoice|business)\b/i', $lower)) {
            return 'work';
        }
        if (preg_match('/\b(buy|offer|sale|discount|deal|promo|coupon|price|free\s*(shipping|trial)|order|purchase|shop|store|product)\b/i', $lower)) {
            return 'promotional';
        }
        if (preg_match('/\b(transaction|payment|paid|credit|debit|bank|account|invoice|bill|receipt|refund|deposit|withdraw|transfer|balance)\b/i', $lower)) {
            return 'transaction';
        }
        if (preg_match('/\b(friend|family|party|dinner|lunch|coffee|hang\s*out|weekend|vacation|holiday|movie|game|fun|home)\b/i', $lower)) {
            return 'social';
        }
        if (preg_match('/\b(party|event|invitation|join|group|meetup|gathering|celebration)\b/i', $lower)) {
            return 'social';
        }

        return 'personal';
    }

    private function fallbackGenerateSummary(array $textMessages, int $maxLength): array
    {
        $lastFive = array_slice($textMessages, -5);
        $sentences = [];

        foreach ($lastFive as $text) {
            $text = trim(preg_replace('/\s+/', ' ', $text));
            $split = preg_split('/(?<=[.!?])\s+/', $text, 2);
            $firstSentence = $split[0] ?? $text;
            if (!empty(trim($firstSentence))) {
                $sentences[] = trim($firstSentence);
            }
        }

        $summary = implode(' ', $sentences);

        if (mb_strlen($summary) > $maxLength) {
            $summary = mb_substr($summary, 0, $maxLength);
            $lastSpace = mb_strrpos($summary, ' ');
            if ($lastSpace !== false) {
                $summary = mb_substr($summary, 0, $lastSpace);
            }
            $summary .= '...';
        }

        return [
            'summary' => $summary,
            'original_length' => mb_strlen($summary),
            'message_count' => count($textMessages)
        ];
    }

    private function fallbackAnalyzeSentiment(string $message): array
    {
        $positiveWords = [
            'happy', 'great', 'awesome', 'amazing', 'wonderful', 'fantastic', 'excellent',
            'good', 'love', 'beautiful', 'perfect', 'best', 'brilliant', 'delighted',
            'grateful', 'thankful', 'joy', 'exciting', 'fun', 'glad', 'pleased',
            'fantastic', 'superb', 'outstanding', 'magnificent', 'splendid', 'marvelous',
            'terrific', 'wonderful', 'nice', 'sweet', 'kind', 'generous', 'helpful',
            'supportive', 'caring', 'thoughtful', 'welcome', 'congratulations', 'celebrate',
            'victory', 'success', 'win', 'achievement', 'proud', 'incredible', 'fabulous',
            'cheerful', 'optimistic', 'positive', 'fantastic', 'lovely', 'delightful',
            'enjoy', 'enjoyed', 'enjoying', 'funny', 'hilarious', 'amusing', 'entertaining',
            'blessed', 'fortunate', 'lucky', 'thrilled', 'ecstatic', 'elated', 'jubilant',
            'peaceful', 'calm', 'relaxed', 'comfortable', 'cozy', 'warm', 'cozy'
        ];

        $negativeWords = [
            'sad', 'angry', 'mad', 'furious', 'upset', 'frustrated', 'annoyed',
            'irritated', 'terrible', 'horrible', 'awful', 'bad', 'worst', 'hate',
            'terrible', 'disgusting', 'awful', 'horrible', 'ugly', 'angry', 'furious',
            'depressed', 'miserable', 'lonely', 'hurt', 'pain', 'painful', 'suffering',
            'crying', 'tears', 'upset', 'disappointed', 'disappointment', 'fail', 'failure',
            'lost', 'losing', 'miss', 'missing', 'regret', 'regretful', 'sorry', 'apologize',
            'apology', 'wrong', 'mistake', 'error', 'problem', 'issue', 'trouble', 'difficult',
            'hard', 'struggle', 'struggling', 'stress', 'stressed', 'anxious', 'worry', 'worried',
            'fear', 'afraid', 'scared', 'terrified', 'horrified', 'panic', 'panicking',
            'boring', 'bored', 'tired', 'exhausted', 'sleepy', 'drowsy', 'sick', 'ill',
            'unwell', 'painful', 'agonizing', 'devastating', 'heartbreaking', 'depressing',
            'gloomy', 'grim', 'dreadful', 'appalling', 'shocking', 'horrendous', 'hideous'
        ];

        $lower = mb_strtolower($message);
        $positiveCount = 0;
        $negativeCount = 0;

        $words = preg_split('/\b|\s+/', $lower);
        foreach ($words as $word) {
            $word = trim(preg_replace('/[^a-z]/', '', $word));
            if (empty($word)) continue;
            if (in_array($word, $positiveWords)) $positiveCount++;
            if (in_array($word, $negativeWords)) $negativeCount++;
        }

        if ($positiveCount > $negativeCount) {
            $total = $positiveCount + $negativeCount;
            $score = $total > 0 ? round(0.5 + ($positiveCount / $total) * 0.5, 2) : 0.6;
            return ['sentiment' => 'positive', 'score' => $score, 'emoji' => '😊'];
        } elseif ($negativeCount > $positiveCount) {
            $total = $positiveCount + $negativeCount;
            $score = $total > 0 ? round(0.5 + ($negativeCount / $total) * 0.5, 2) : 0.6;
            return ['sentiment' => 'negative', 'score' => $score, 'emoji' => '😞'];
        }

        return ['sentiment' => 'neutral', 'score' => 0.5, 'emoji' => '😐'];
    }

    private function fallbackSmartSearch(string $query, string $userId): array
    {
        $synonymMap = [
            'hi' => ['hello', 'hey', 'greetings'],
            'hello' => ['hi', 'hey', 'greetings'],
            'hey' => ['hi', 'hello', 'greetings'],
            'bye' => ['goodbye', 'see you', 'farewell'],
            'goodbye' => ['bye', 'see you', 'farewell'],
            'thanks' => ['thank you', 'thankyou', 'thx', 'ty'],
            'thank' => ['thanks', 'grateful', 'appreciate'],
            'ok' => ['okay', 'k', 'sure', 'alright'],
            'okay' => ['ok', 'k', 'sure', 'alright'],
            'love' => ['like', 'adore', 'care'],
            'hate' => ['dislike', 'detest', 'loathe'],
            'lol' => ['haha', 'funny', 'laugh'],
            'omg' => ['wow', 'oh my god', 'unbelievable'],
            'idk' => ["don't know", "dunno", "not sure"],
            'pls' => ['please', 'plz'],
            'please' => ['pls', 'plz'],
            'u' => ['you'],
            'r' => ['are'],
            'ur' => ['your', "you're"],
            'msg' => ['message', 'text'],
            'phone' => ['mobile', 'cell', 'number'],
            'meet' => ['meeting', 'appointment', 'schedule'],
            'info' => ['information', 'details', 'data'],
            'help' => ['assist', 'support', 'aid'],
            'btw' => ['by the way'],
            'tmrw' => ['tomorrow'],
            'today' => ['now', 'this morning'],
            'tonight' => ['this evening', 'later'],
        ];

        $messageModel = new MessageModel();
        $userModel = new UserModel();

        $allMessages = $messageModel
            ->where('m_sender_id', $userId)
            ->orWhere('m_receiver_id', $userId)
            ->orderBy('m_epoch_time', 'DESC')
            ->findAll();

        $queryLower = mb_strtolower($query);
        $queryWords = preg_split('/\s+/', $queryLower);
        $queryWords = array_filter($queryWords, fn($w) => strlen($w) > 1);

        $expandedTerms = [$queryLower];
        foreach ($queryWords as $word) {
            if (isset($synonymMap[$word])) {
                $expandedTerms = array_merge($expandedTerms, $synonymMap[$word]);
            }
        }
        $expandedTerms = array_unique($expandedTerms);

        $results = [];
        foreach ($allMessages as $msg) {
            $content = $msg['m_message'] ?? '';
            $contentLower = mb_strtolower($content);
            if (empty($content)) continue;

            $score = 0;
            $matchedTerms = [];

            foreach ($expandedTerms as $term) {
                $term = trim($term);
                if (empty($term)) continue;

                if ($contentLower === $term) {
                    $score += 1.0;
                    $matchedTerms[] = $term;
                } elseif (strpos($contentLower, $term) !== false) {
                    $termLen = strlen($term);
                    $score += ($termLen > 5) ? 0.6 : 0.4;
                    $matchedTerms[] = $term;
                }
            }

            if ($score > 0) {
                $score = round(min($score, 1.0), 2);
                $sender = 'Unknown';
                try {
                    $senderData = $userModel->find($msg['m_sender_id']);
                    $sender = $senderData ? ($senderData['u_name'] ?? 'Unknown') : 'Unknown';
                } catch (\Throwable $e) {
                }

                $results[] = [
                    'message' => $content,
                    'sender' => $sender,
                    'sender_id' => $msg['m_sender_id'],
                    'date' => $msg['m_epoch_time'] ?? '',
                    'room_id' => $msg['m_room_id'] ?? '',
                    'relevance_score' => $score,
                    'matched_terms' => array_values(array_unique($matchedTerms))
                ];
            }
        }

        usort($results, function ($a, $b) {
            return $b['relevance_score'] <=> $a['relevance_score'];
        });

        return [
            'results' => array_slice($results, 0, 20),
            'total_found' => count($results),
            'query' => $query
        ];
    }

    private function fallbackTranslateMessage(string $message, string $langName, array $languageMap): array
    {
        $translatedText = "[{$langName}] {$message}";

        if (preg_match('/\b(hello|hi|hey)\b/i', $message)) {
            $greetings = [
                'Spanish' => '¡Hola!', 'French' => 'Bonjour!', 'German' => 'Hallo!',
                'Italian' => 'Ciao!', 'Portuguese' => 'Olá!', 'Russian' => 'Привет!',
                'Japanese' => 'こんにちは!', 'Korean' => '안녕하세요!', 'Chinese' => '你好!',
                'Arabic' => 'مرحبا!', 'Hindi' => 'नमस्ते!', 'Bengali' => 'হ্যালো!',
                'Tamil' => 'வணக்கம்!', 'Telugu' => 'హలో!', 'Marathi' => 'नमस्कार!',
            ];
            if (isset($greetings[$langName])) {
                $translatedText = preg_replace('/\b(hello|hi|hey)\b/i', $greetings[$langName], $message, 1);
            }
        }

        return [
            'translated_text' => $translatedText,
            'source_language' => 'en',
            'target_language' => $langName,
            'is_mock' => true
        ];
    }

    private function fallbackDetectSpam(string $message): array
    {
        $lower = mb_strtolower($message);
        $reasons = [];
        $spamScore = 0;

        $linkCount = preg_match_all('/https?:\/\/[^\s]+|www\.[^\s]+/i', $message);
        if ($linkCount > 2) {
            $spamScore += 0.3;
            $reasons[] = 'Excessive links (' . $linkCount . ' URLs found)';
        } elseif ($linkCount > 0) {
            $spamScore += 0.1;
        }

        $capsRatio = 0;
        $letters = preg_replace('/[^a-zA-Z]/', '', $message);
        $capsLetters = preg_replace('/[^A-Z]/', '', $message);
        if (strlen($letters) > 0) {
            $capsRatio = strlen($capsLetters) / strlen($letters);
        }
        if ($capsRatio > 0.7 && strlen($letters) > 5) {
            $spamScore += 0.2;
            $reasons[] = 'Excessive capitalization (' . round($capsRatio * 100) . '% uppercase)';
        }

        $spamKeywords = [
            'win', 'winner', 'prize', 'lottery', 'jackpot', 'free money', 'cash bonus',
            'click here', 'subscribe', 'limited offer', 'act now', 'don\'t miss',
            'congratulations', 'you\'ve won', 'claim now', 'exclusive deal', 'buy now',
            'earn money', 'work from home', 'make money', 'investment', 'guaranteed',
            'no risk', 'risk free', 'free trial', 'sign up', 'click below', 'urgent',
            'million', 'billion', 'dollar', '$$$', '100%', 'satisfaction guaranteed',
            'meet singles', 'dating', 'adult', 'cheap', 'pills', 'medication', 'prescription',
            'no prescription', 'weight loss', 'enlarge', 'penis', 'viagra', 'cialis'
        ];

        foreach ($spamKeywords as $keyword) {
            if (strpos($lower, $keyword) !== false) {
                $spamScore += 0.15;
                $reasons[] = 'Contains spam keyword: "' . $keyword . '"';
            }
        }

        if (preg_match('/!{3,}|\?{3,}|\.{4,}/', $message)) {
            $spamScore += 0.1;
            $reasons[] = 'Excessive punctuation';
        }

        if (preg_match('/\b\d{10,}\b/', $message)) {
            $spamScore += 0.1;
            $reasons[] = 'Contains long numeric sequence';
        }

        $allCapsWords = preg_match_all('/\b[A-Z]{4,}\b/', $message);
        if ($allCapsWords > 2) {
            $spamScore += 0.1;
            $reasons[] = 'Excessive SHOUTING (' . $allCapsWords . ' all-caps words)';
        }

        if (preg_match('/\.(xyz|top|loan|click|work|download|gq|ml|ga|cf)\b/i', $message)) {
            $spamScore += 0.2;
            $reasons[] = 'Suspicious domain extension detected';
        }

        $spamScore = round(min($spamScore, 1.0), 2);
        $isSpam = $spamScore >= 0.4;

        if (!$isSpam && count($reasons) > 0) {
            $reasons = ['No significant spam indicators detected'];
        } elseif (!$isSpam) {
            $reasons = ['No significant spam indicators detected'];
        }

        return [
            'is_spam' => $isSpam,
            'confidence' => $spamScore,
            'reason' => implode('; ', array_slice(array_unique($reasons), 0, 3))
        ];
    }

    private function fallbackChatbotResponse(string $message): array
    {
        $lower = mb_strtolower($message);

        if (preg_match('/\b(hi|hello|hey|greetings|howdy)\b/i', $lower)) {
            $response = "Hello! I'm your Humming Bird assistant. How can I help you today?";
        } elseif (preg_match('/\b(how are you|how\'s it going|how do you do)\b/i', $lower)) {
            $response = "I'm doing great, thank you for asking! I'm here to help you with anything you need.";
        } elseif (preg_match('/\b(bye|goodbye|see you|farewell|talk later)\b/i', $lower)) {
            $response = "Goodbye! Have a wonderful day! Feel free to reach out anytime.";
        } elseif (preg_match('/\b(thank|thanks|thx|ty)\b/i', $lower)) {
            $response = "You're welcome! I'm always here when you need me.";
        } elseif (preg_match('/\b(help|assist|support)\b/i', $lower)) {
            $response = "I can help you with sending messages, scheduling, searching your conversations, and more. What would you like to do?";
        } elseif (preg_match('/\b(schedule|remind|reminder|appointment)\b/i', $lower)) {
            $response = "I can help you schedule messages! Just tell me what message you want to send, to whom, and when. Use the schedule section to set it up.";
        } elseif (preg_match('/\b(message|text|chat|conversation)\b/i', $lower)) {
            $response = "You can send messages to any of your contacts. Your messages are end-to-end encrypted for privacy.";
        } elseif (preg_match('/\b(search|find|lookup)\b/i', $lower)) {
            $response = "Use the search feature to find any message in your conversations. You can search by keyword, contact name, or date.";
        } elseif (preg_match('/\b(group|team|room)\b/i', $lower)) {
            $response = "You can create groups to chat with multiple people at once. Groups support admin roles, media sharing, and more.";
        } elseif (preg_match('/\b(encrypt|secure|privacy|safe)\b/i', $lower)) {
            $response = "Your privacy is our priority. All messages are encrypted with multiple layers of security to ensure only you and your recipients can read them.";
        } elseif (preg_match('/\b(weather|time|date)\b/i', $lower)) {
            $response = "I'm primarily a messaging assistant, but I can help you with your conversations and schedules!";
        } elseif (preg_match('/\b(what can you do|capabilities|features|skills)\b/i', $lower)) {
            $response = "I can: suggest replies to messages, categorize your conversations, summarize chats, detect spam, translate messages, analyze sentiment, and perform smart searches across your messages!";
        } elseif (preg_match('/\b(name|who are you|your name)\b/i', $lower)) {
            $response = "I'm Humming Bird AI, your intelligent messaging assistant. I'm here to make your communication smarter and easier!";
        } else {
            $responses = [
                "That's interesting! Tell me more about it.",
                "I understand. Is there anything specific you'd like me to help with?",
                "Got it! I'm here if you need any assistance.",
                "Thanks for sharing! How can I assist you further?",
                "I see. Would you like me to help with anything related to your messages?"
            ];
            $response = $responses[array_rand($responses)];
        }

        return [
            'response' => $response,
            'user_message' => $message
        ];
    }

    private function fallbackNotificationGroup(array $notifications): array
    {
        $groups = [];

        $categoryKeywords = [
            'missed_call' => ['missed call', 'missed call from', 'call missed'],
            'new_message' => ['new message', 'message from', 'sent you a message', 'text from'],
            'group_invite' => ['invited you', 'group invitation', 'added you to', 'join group'],
            'friend_request' => ['friend request', 'connection request', 'follow request'],
            'mention' => ['mentioned you', 'tagged you', '@'],
            'payment' => ['payment', 'paid', 'received', 'transferred', 'sent you money'],
            'reminder' => ['reminder', 'remind', 'due', 'upcoming'],
            'system' => ['update', 'maintenance', 'system', 'security', 'alert'],
            'social' => ['like', 'comment', 'share', 'reacted', 'post'],
            'promotional' => ['offer', 'sale', 'discount', 'promo', 'deal'],
        ];

        foreach ($notifications as $notif) {
            $title = $notif['title'] ?? $notif['text'] ?? $notif['message'] ?? '';
            $body = $notif['body'] ?? $notif['description'] ?? '';
            $sender = $notif['sender'] ?? $notif['from'] ?? $notif['sender_id'] ?? 'system';
            $type = $notif['type'] ?? '';
            $timestamp = $notif['timestamp'] ?? $notif['time'] ?? $notif['date'] ?? time();
            $notificationId = $notif['id'] ?? uniqid();

            $combinedText = mb_strtolower($title . ' ' . $body);
            $category = 'other';

            foreach ($categoryKeywords as $cat => $keywords) {
                foreach ($keywords as $keyword) {
                    if (strpos($combinedText, $keyword) !== false) {
                        $category = $cat;
                        break 2;
                    }
                }
            }

            if (!empty($type) && isset($categoryKeywords[$type])) {
                $category = $type;
            }

            $groupKey = $category . '_' . $sender;

            if (!isset($groups[$groupKey])) {
                $groups[$groupKey] = [
                    'category' => $category,
                    'sender' => $sender,
                    'title' => $this->getNotificationGroupTitle($category, $sender),
                    'notifications' => [],
                    'count' => 0,
                    'first_timestamp' => $timestamp,
                    'last_timestamp' => $timestamp
                ];
            }

            $groups[$groupKey]['notifications'][] = [
                'id' => $notificationId,
                'title' => $title,
                'body' => $body,
                'sender' => $sender,
                'timestamp' => $timestamp
            ];
            $groups[$groupKey]['count']++;
            if ($timestamp > $groups[$groupKey]['last_timestamp']) {
                $groups[$groupKey]['last_timestamp'] = $timestamp;
            }
            if ($timestamp < $groups[$groupKey]['first_timestamp']) {
                $groups[$groupKey]['first_timestamp'] = $timestamp;
            }
        }

        return [
            'groups' => array_values($groups),
            'total_groups' => count($groups),
            'total_notifications' => count($notifications)
        ];
    }

    private function getNotificationGroupTitle(string $category, string $sender): string
    {
        $titles = [
            'missed_call' => "Missed calls from {$sender}",
            'new_message' => "Messages from {$sender}",
            'group_invite' => 'Group invitations',
            'friend_request' => 'Friend requests',
            'mention' => 'Mentions',
            'payment' => 'Payment notifications',
            'reminder' => 'Reminders',
            'system' => 'System notifications',
            'social' => 'Social interactions',
            'promotional' => 'Offers & promotions',
            'other' => "Notifications from {$sender}"
        ];
        return $titles[$category] ?? "Notifications from {$sender}";
    }
}
