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

    // ─── Expand teacher observation shorthand ───
    case 'expand_observation':
        $shorthand = trim($body['shorthand'] ?? '');
        if (!$shorthand) {
            jsonResponse(['error' => 'Shorthand text required'], 400);
        }

        $prompt = 'A piano teacher wrote this quick shorthand observation during a lesson: "' . $shorthand . '". Expand this into a clear, well-written teacher observation note. Keep the meaning exactly the same but make it readable and professional. Preserve any musical terminology. Stay concise, one or two sentences. This is a private teacher note, not student-facing. Respond with a JSON object: {"expanded": "the expanded observation text"}';

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


    // ─── Generate a full lesson document from brief description ───
    case 'generate_lesson':
        $contentId = trim($body['content_id'] ?? '');
        $brief = trim($body['brief'] ?? '');
        $title = trim($body['title'] ?? '');
        $track = trim($body['track'] ?? '');
        $type = trim($body['type'] ?? '');
        $description = trim($body['description'] ?? '');

        if (!$brief && !$description) {
            jsonResponse(['error' => 'Brief or description required'], 400);
        }

        $lessonPrompt = "You are creating a complete, rich lesson document for a piano student. The teacher has given you a brief description, and you need to create a full, well-structured lesson page.

The teacher wrote: \"{$brief}{$description}\"
" . ($title ? "Content title: \"$title\"\n" : "") . ($track ? "Track: $track\n" : "") . ($type ? "Type: $type\n" : "") . "

Generate a comprehensive lesson document in HTML format. The HTML should be self-contained content (no <html>, <head>, or <body> tags — just the inner content divs).

Structure the lesson with these sections as appropriate:
1. **Overview** — What this lesson covers and why it matters
2. **Key Concepts** — Theory or background the student needs
3. **Step-by-Step Instructions** — Detailed practice steps, numbered
4. **Tips & Common Mistakes** — Practical advice
5. **Goals** — What the student should aim for / how they know they\'ve got it
6. **Going Further** — Optional next steps or variations

Style guidelines:
- Use clear, encouraging language suitable for the student\'s level
- Be specific about notes, keys, fingerings, tempos where relevant
- Include musical terminology with brief explanations
- Use <h2> for section titles, <h3> for subsections
- Use <ol> for ordered steps, <ul> for tips/lists
- Use <strong> for key terms
- Use <div class=\"piano-tip\"> for callout boxes with tips
- Use <div class=\"practice-box\"> for specific practice exercises
- Keep it thorough but not overwhelming — aim for 400-800 words

Respond with a JSON object:
{
  \"lesson_html\": \"the complete HTML content\",
  \"summary\": \"one-line summary of what the lesson covers\"
}";

        $result = callClaude($MUSIC_SYSTEM . "\n\nFor this request, generate HTML content for a lesson document. Still respond in valid JSON.", $lessonPrompt, 4096);
        $parsed = json_decode($result, true);
        if (!$parsed) {
            preg_match('/\{.*\}/s', $result, $m);
            $parsed = json_decode($m[0] ?? '{}', true);
        }

        // If we have a content_id, save the lesson to the database
        if ($contentId && isset($parsed['lesson_html'])) {
            $db = getDB();
            $stmt = $db->prepare('UPDATE content SET lesson_content = ? WHERE id = ? AND teacher_id = ?');
            $stmt->execute([$parsed['lesson_html'], $contentId, $teacherId]);
        }

        jsonResponse(['lesson' => $parsed]);
        break;

    case 'fix_enharmonics':
        $map = $body['enharmonic_map'] ?? null;
        if (!$map) jsonResponse(['error' => 'enharmonic_map required'], 400);

        $prompt = 'You are a music theory expert. Review and fix this enharmonic note spelling map for a piano teaching app. The map has 12 keys (0-11, representing C through B). Each key maps to an array of 12 note name strings representing the correct enharmonic spelling of each chromatic note when in that root key context.

Rules:
- Use standard music theory enharmonic conventions
- Sharp keys (G, D, A, E, B, F#) should use sharps
- Flat keys (F, Bb, Eb, Ab, Db) should use flats  
- C can use a mix (F# and Bb are standard)
- Avoid double sharps/flats where a simpler name exists
- The root note itself must always be spelled with its standard name
- Scale degrees should follow standard naming (e.g. in F# major: F# G# A# B C# D# E#)

Current map:
' . json_encode($map, JSON_PRETTY_PRINT) . '

Return ONLY a JSON object: {"enharmonic_map": {... the corrected map ...}}';

        $result = callClaude($MUSIC_SYSTEM, $prompt, 2048);
        $parsed = json_decode($result, true);
        if (!$parsed) {
            preg_match('/\{.*\}/s', $result, $m);
            $parsed = json_decode($m[0] ?? '{}', true);
        }
        jsonResponse($parsed ?: ['error' => 'AI returned invalid JSON']);
        break;

    // ─── Generate cover art SVG for content ───
    case 'generate_cover_art':
        $title = trim($body['title'] ?? '');
        $track = trim($body['track'] ?? 'Jazz');
        $type = trim($body['type'] ?? 'Practice');
        $contentId = trim($body['content_id'] ?? '');
        if (!$title) jsonResponse(['error' => 'Title required'], 400);

        // Ask AI to generate an SVG cover art design
        $svgPrompt = "Create a beautiful, artistic SVG cover art image (400x400) for a piano piece called \"$title\" (genre: $track, type: $type).

Requirements:
- Output ONLY the raw SVG code, starting with <svg and ending with </svg>
- Size: viewBox=\"0 0 400 400\"
- Use a rich, atmospheric color palette appropriate to the genre:
  - Jazz: deep blues, amber, smoky purples
  - Contemporary: bright gradients, modern teal/coral
  - Foundation: warm earth tones, gold, cream
  - Crossover: vibrant mixed palette
- Include abstract musical elements: piano keys, music notes, sound waves, staff lines, circles, geometric shapes
- Make it visually striking with gradients, layered shapes, and depth
- Do NOT include any readable text or words — purely visual/abstract
- Use SVG elements: rect, circle, ellipse, path, polygon, line, linearGradient, radialGradient
- Make it feel like professional album art";

        $svgResult = callClaude($MUSIC_SYSTEM . "\n\nFor this request, generate SVG artwork. Output ONLY the SVG code, no JSON wrapping, no markdown fences, no explanation.", $svgPrompt, 4096);

        // Extract SVG from response (in case AI wrapped it)
        if (preg_match('/<svg[\s\S]*<\/svg>/i', $svgResult, $svgMatch)) {
            $svg = $svgMatch[0];
        } else {
            jsonResponse(['error' => 'AI did not generate valid SVG', 'raw' => substr($svgResult, 0, 500)], 500);
        }

        // Save SVG as file
        $uploadDir = __DIR__ . '/../uploads/covers/';
        if (!is_dir($uploadDir)) mkdir($uploadDir, 0755, true);

        $filename = bin2hex(random_bytes(12)) . '.svg';
        file_put_contents($uploadDir . $filename, $svg);
        $localUrl = 'uploads/covers/' . $filename;

        // Save to content record if content_id provided
        if ($contentId) {
            $db = getDB();
            try { $db->exec('ALTER TABLE content ADD COLUMN cover_image_url VARCHAR(500) DEFAULT NULL'); } catch (PDOException $e) {}
            $stmt = $db->prepare('UPDATE content SET cover_image_url = ? WHERE id = ? AND teacher_id = ?');
            $stmt->execute([$localUrl, $contentId, $teacherId]);
        }

        jsonResponse(['cover_url' => $localUrl]);
        break;

    default:
        jsonResponse(['error' => 'Invalid action. Use: generate_content, expand_shorthand, expand_observation, youtube_import, youtube_bulk_import, build_path, bulk_generate, generate_lesson, fix_enharmonics, generate_cover_art'], 400);
}
