<?php
// My Woodshed Music — AI API (powered by Anthropic Claude)
// POST /api/ai.php?action=generate_content     — create content from description
// POST /api/ai.php?action=expand_shorthand     — expand shorthand into student instructions
// POST /api/ai.php?action=youtube_import       — extract & describe YouTube video
// POST /api/ai.php?action=build_path           — AI-generate a practice path
// POST /api/ai.php?action=bulk_generate        — bulk create chord/scale exercises

require_once __DIR__ . '/helpers.php';

$action = $_GET['action'] ?? '';
$body = getBody();
$teacherId = requireAuth();

// ─── MiniMax API call (OpenAI-compatible) ───
function callClaude($systemPrompt, $userMessage, $maxTokens = 1024) {
    $apiKey = defined('MINIMAX_API_KEY') ? MINIMAX_API_KEY : '';
    if (!$apiKey) {
        jsonResponse(['error' => 'MiniMax API key not configured. Add it to config.php.'], 500);
    }

    $payload = [
        'model' => 'MiniMax-M1',
        'max_tokens' => $maxTokens,
        'messages' => [
            ['role' => 'system', 'content' => $systemPrompt],
            ['role' => 'user', 'content' => $userMessage]
        ]
    ];

    $ch = curl_init('https://api.minimax.io/v1/text/chatcompletion_v2?GroupId=2032839200885191396');
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_HTTPHEADER => [
            'Content-Type: application/json',
            'Authorization: Bearer ' . $apiKey
        ],
        CURLOPT_POSTFIELDS => json_encode($payload),
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 60
    ]);

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($httpCode !== 200) {
        $err = json_decode($response, true);
        $detail = $err['error']['message'] ?? $err['base_resp']['status_msg'] ?? $response;
        jsonResponse(['error' => 'AI request failed', 'detail' => $detail], 500);
    }

    $result = json_decode($response, true);
    // OpenAI-compatible response format
    return $result['choices'][0]['message']['content'] ?? '';
}

// ─── YouTube oEmbed metadata ───
function getYouTubeInfo($url) {
    // Extract video ID
    preg_match('/(?:youtube\.com\/watch\?v=|youtu\.be\/|youtube\.com\/embed\/)([a-zA-Z0-9_-]{11})/', $url, $matches);
    $videoId = $matches[1] ?? '';
    if (!$videoId) return null;

    // oEmbed for title and author
    $oembed = file_get_contents("https://www.youtube.com/oembed?url=https://www.youtube.com/watch?v=$videoId&format=json");
    if (!$oembed) return null;

    $data = json_decode($oembed, true);
    return [
        'video_id' => $videoId,
        'title' => $data['title'] ?? '',
        'author' => $data['author_name'] ?? '',
        'thumbnail' => "https://img.youtube.com/vi/$videoId/hqdefault.jpg",
        'embed_url' => "https://www.youtube.com/embed/$videoId",
        'url' => "https://www.youtube.com/watch?v=$videoId"
    ];
}

// ─── System prompt for music teaching context ───
$MUSIC_SYSTEM = "You are an AI assistant for My Woodshed Music, a piano teaching app. The teacher (Mark) is a jazz pianist who teaches students across all levels — from beginners and kids to serious jazz students.

Content in this app has:
- A TITLE (short, descriptive)
- A TYPE: one of Watch, Play, Practice, Listen, Review
- A TRACK: one of Jazz, Contemporary, Foundation, Crossover
- A DESCRIPTION: student-facing instructions that are clear, encouraging, and specific about what to do

The tracks mean:
- Foundation: core technique, scales, chords, reading, theory fundamentals
- Jazz: jazz-specific skills — voicings, comping, improv, standards, ear training
- Contemporary: pop, rock, R&B, singer-songwriter styles
- Crossover: pieces or skills that blend genres

The types mean:
- Watch: video content to observe and absorb
- Play: play through a piece or exercise hands-on
- Practice: focused repetition on a specific skill or passage
- Listen: active listening to recordings for style, feel, or analysis
- Review: revisit previously learned material

Always respond in valid JSON. Never include markdown formatting or code fences in your response.";

switch ($action) {

    // ─── Generate content from a description ───
    case 'generate_content':
        $description = trim($body['description'] ?? '');
        if (!$description) {
            jsonResponse(['error' => 'Description required'], 400);
        }

        $prompt = "The teacher wants to create a new content item. Based on this description, generate a content entry.

Teacher's description: \"$description\"

Respond with a JSON object:
{
  \"title\": \"short descriptive title\",
  \"type\": \"Watch|Play|Practice|Listen|Review\",
  \"track\": \"Jazz|Contemporary|Foundation|Crossover\",
  \"description\": \"student-facing instructions, 2-4 sentences, clear and encouraging\"
}";

        $result = callClaude($MUSIC_SYSTEM, $prompt);
        $parsed = json_decode($result, true);
        if (!$parsed) {
            // Try to extract JSON from response
            preg_match('/\{.*\}/s', $result, $m);
            $parsed = json_decode($m[0] ?? '{}', true);
        }
        jsonResponse(['content' => $parsed, 'raw' => $result]);
        break;

    // ─── Expand shorthand into student instructions ───
    case 'expand_shorthand':
        $shorthand = trim($body['shorthand'] ?? '');
        if (!$shorthand) {
            jsonResponse(['error' => 'Shorthand text required'], 400);
        }

        $prompt = "The teacher wrote this shorthand note for a practice assignment step:

\"$shorthand\"

Expand this into clear, student-facing instructions. Be specific about what to do, how to practise it, and what to aim for. Keep it encouraging but concise (2-4 sentences). If tempo or key details are implied, include them.

Respond with a JSON object:
{
  \"expanded\": \"the expanded student-facing instruction text\"
}";

        $result = callClaude($MUSIC_SYSTEM, $prompt);
        $parsed = json_decode($result, true);
        if (!$parsed) {
            preg_match('/\{.*\}/s', $result, $m);
            $parsed = json_decode($m[0] ?? '{}', true);
        }
        jsonResponse($parsed);
        break;

    // ─── YouTube import — get metadata + AI description ───
    case 'youtube_import':
        $url = trim($body['url'] ?? '');
        if (!$url) {
            jsonResponse(['error' => 'YouTube URL required'], 400);
        }

        $info = getYouTubeInfo($url);
        if (!$info) {
            jsonResponse(['error' => 'Could not extract YouTube video info'], 400);
        }

        $prompt = "A piano teacher wants to add this YouTube video to their content library.

Video title: \"{$info['title']}\"
Channel: \"{$info['author']}\"
URL: {$info['url']}

Based on the title and channel, generate a content entry for this video. Infer the most likely type and track.

Respond with a JSON object:
{
  \"title\": \"a clean, concise title for the library\",
  \"type\": \"Watch|Play|Practice|Listen|Review\",
  \"track\": \"Jazz|Contemporary|Foundation|Crossover\",
  \"description\": \"student-facing description of what they'll learn from this video, 2-3 sentences\"
}";

        $result = callClaude($MUSIC_SYSTEM, $prompt);
        $parsed = json_decode($result, true);
        if (!$parsed) {
            preg_match('/\{.*\}/s', $result, $m);
            $parsed = json_decode($m[0] ?? '{}', true);
        }

        jsonResponse([
            'youtube' => $info,
            'content' => $parsed
        ]);
        break;

    // ─── Bulk YouTube import (multiple URLs) ───
    case 'youtube_bulk_import':
        $urls = $body['urls'] ?? [];
        if (empty($urls) || !is_array($urls)) {
            jsonResponse(['error' => 'Array of YouTube URLs required'], 400);
        }

        $results = [];
        foreach ($urls as $url) {
            $url = trim($url);
            $info = getYouTubeInfo($url);
            if (!$info) {
                $results[] = ['url' => $url, 'error' => 'Could not extract video info'];
                continue;
            }

            $prompt = "A piano teacher wants to add this YouTube video to their content library.

Video title: \"{$info['title']}\"
Channel: \"{$info['author']}\"

Respond with a JSON object:
{
  \"title\": \"a clean, concise title\",
  \"type\": \"Watch|Play|Practice|Listen|Review\",
  \"track\": \"Jazz|Contemporary|Foundation|Crossover\",
  \"description\": \"student-facing description, 2-3 sentences\"
}";

            $result = callClaude($MUSIC_SYSTEM, $prompt, 512);
            $parsed = json_decode($result, true);
            if (!$parsed) {
                preg_match('/\{.*\}/s', $result, $m);
                $parsed = json_decode($m[0] ?? '{}', true);
            }

            $results[] = [
                'youtube' => $info,
                'content' => $parsed
            ];
        }

        jsonResponse(['items' => $results]);
        break;

    // ─── Build a practice path from existing library ───
    case 'build_path':
        $brief = trim($body['brief'] ?? '');
        $studentLevel = trim($body['student_level'] ?? '');
        if (!$brief) {
            jsonResponse(['error' => 'Brief description of the practice path required'], 400);
        }

        // Fetch teacher's existing content library
        $db = getDB();
        $stmt = $db->prepare('SELECT id, title, type, track, description FROM content WHERE teacher_id = ? ORDER BY title');
        $stmt->execute([$teacherId]);
        $library = $stmt->fetchAll();

        $libraryJson = json_encode($library);

        $prompt = "The teacher wants to build a weekly practice path (assignment) for a student.

Teacher's brief: \"$brief\"" . ($studentLevel ? "\nStudent level: \"$studentLevel\"" : "") . "

Here is the teacher's existing content library:
$libraryJson

Create a practice path of 4-7 steps using items from the library where possible. For any gaps, suggest NEW content items to create.

Respond with a JSON object:
{
  \"week_label\": \"a descriptive label for this week, e.g. 'Week 1: Blues Foundations'\",
  \"steps\": [
    {
      \"source\": \"library\" or \"new\",
      \"content_id\": \"id from library if source is library, null if new\",
      \"title\": \"title of the content item\",
      \"type\": \"Watch|Play|Practice|Listen|Review\",
      \"track\": \"Jazz|Contemporary|Foundation|Crossover\",
      \"notes\": \"step-specific notes for this student\",
      \"new_description\": \"full description if this is a new content item, null if from library\"
    }
  ]
}";

        $result = callClaude($MUSIC_SYSTEM, $prompt, 2048);
        $parsed = json_decode($result, true);
        if (!$parsed) {
            preg_match('/\{.*\}/s', $result, $m);
            $parsed = json_decode($m[0] ?? '{}', true);
        }
        jsonResponse(['path' => $parsed]);
        break;

    // ─── Bulk generate chord/scale exercises ───
    case 'bulk_generate':
        $category = trim($body['category'] ?? '');
        $details = trim($body['details'] ?? '');
        if (!$category) {
            jsonResponse(['error' => 'Category required (e.g. "major scales", "7th chord voicings", "ii-V-I progressions")'], 400);
        }

        $prompt = "The teacher wants to bulk-generate content items for their library.

Category: \"$category\"" . ($details ? "\nAdditional details: \"$details\"" : "") . "

Generate a set of content items covering all 12 keys or the most useful variations. Each item should be a complete library entry.

Respond with a JSON object:
{
  \"items\": [
    {
      \"title\": \"short descriptive title\",
      \"type\": \"Watch|Play|Practice|Listen|Review\",
      \"track\": \"Jazz|Contemporary|Foundation|Crossover\",
      \"description\": \"student-facing instructions, 2-4 sentences\"
    }
  ]
}

Generate between 6 and 24 items depending on what makes sense for the category.";

        $result = callClaude($MUSIC_SYSTEM, $prompt, 4096);
        $parsed = json_decode($result, true);
        if (!$parsed) {
            preg_match('/\{.*\}/s', $result, $m);
            $parsed = json_decode($m[0] ?? '{}', true);
        }
        jsonResponse($parsed);
        break;

    default:
        jsonResponse(['error' => 'Invalid action. Use: generate_content, expand_shorthand, youtube_import, youtube_bulk_import, build_path, bulk_generate'], 400);
}
