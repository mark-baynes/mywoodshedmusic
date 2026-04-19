<?php
// My Woodshed Music — AI API (powered by Anthropic Claude)
error_reporting(E_ALL);
ini_set('display_errors', 0);
ini_set('log_errors', 1);
// Catch fatal errors and return JSON
register_shutdown_function(function() {
    $error = error_get_last();
    if ($error && in_array($error['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR])) {
        if (!headers_sent()) {
            header('Content-Type: application/json');
            http_response_code(500);
        }
        echo json_encode(['error' => 'PHP Fatal: ' . $error['message'], 'file' => basename($error['file']), 'line' => $error['line']]);
    }
});
// POST /api/ai.php?action=generate_content     — create content from description
// POST /api/ai.php?action=expand_shorthand     — expand shorthand into student instructions
// POST /api/ai.php?action=youtube_import       — extract & describe YouTube video
// POST /api/ai.php?action=build_path           — AI-generate a practice path
// POST /api/ai.php?action=bulk_generate        — bulk create chord/scale exercises

require_once __DIR__ . '/helpers.php';

// AI calls can take a while — extend PHP execution time
set_time_limit(180);

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
    $curlError = curl_error($ch);
    curl_close($ch);

    if ($response === false || $curlError) {
        jsonResponse(['error' => 'AI request failed: ' . ($curlError ?: 'connection error')], 500);
    }

    if ($httpCode !== 200) {
        $err = json_decode($response, true);
        $detail = $err['error']['message'] ?? $err['base_resp']['status_msg'] ?? $response;
        jsonResponse(['error' => 'AI request failed', 'detail' => $detail], 500);
    }

    $result = json_decode($response, true);
    if (!$result || !isset($result['choices'][0]['message']['content'])) {
        jsonResponse(['error' => 'AI returned invalid response', 'detail' => substr($response, 0, 300)], 500);
    }
    // OpenAI-compatible response format
    return $result['choices'][0]['message']['content'];
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

    // ─── Refine YouTube import with teacher corrections ───
    case 'youtube_refine':
        $original = $body['original'] ?? [];
        $correction = trim($body['correction'] ?? '');
        $youtubeTitle = trim($body['youtubeTitle'] ?? '');
        $youtubeAuthor = trim($body['youtubeAuthor'] ?? '');

        if (!$correction) {
            jsonResponse(['error' => 'Correction text required'], 400);
        }

        $originalJson = json_encode($original, JSON_PRETTY_PRINT);
        $context = $youtubeTitle ? 'YouTube video: "' . $youtubeTitle . '"' . ($youtubeAuthor ? ' by ' . $youtubeAuthor : '') : 'Content from the library';
        $prompt = 'A piano teacher has a content entry in their library and wants to correct the details.

' . $context . '

Current content entry:
' . $originalJson . '

The teacher provides this correction in shorthand:
"' . $correction . '"

Rebuild the content entry incorporating the teacher corrections. The teacher knows best — if they say it is a ballad in Bb, trust that completely. Expand any shorthand naturally (e.g. "Bb" becomes "B flat major", "intermed" means suitable for intermediate players). Keep the description student-facing, 2-3 sentences.

Respond with a JSON object:
{
  "title": "corrected title",
  "type": "Watch|Play|Practice|Listen|Review",
  "track": "Jazz|Contemporary|Foundation|Crossover",
  "description": "corrected student-facing description"
}';

        $result = callClaude($MUSIC_SYSTEM, $prompt);
        $parsed = json_decode($result, true);
        if (!$parsed) {
            preg_match('/\{.*\}/s', $result, $m);
            $parsed = json_decode($m[0] ?? '{}', true);
        }

        jsonResponse(['content' => $parsed]);
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

        // Load teacher's lesson style preferences
        $styleNote = "";
        try {
            $db = getDB();
            $styleStmt = $db->prepare('SELECT * FROM teacher_lesson_style WHERE teacher_id = ?');
            $styleStmt->execute([$teacherId]);
            $style = $styleStmt->fetch(PDO::FETCH_ASSOC);
            if ($style) {
                $parts = [];
                if ($style['tone']) $parts[] = "Tone: {$style['tone']}";
                if ($style['detail_level']) $parts[] = "Detail level: {$style['detail_level']}";
                if ($style['lesson_length']) {
                    $lengths = ['short' => '200-400 words', 'medium' => '400-800 words', 'long' => '800+ words'];
                    $parts[] = "Target length: " . ($lengths[$style['lesson_length']] ?? $style['lesson_length']);
                }
                $sections = [];
                if (!$style['include_theory']) $sections[] = "Skip theory/background section";
                if (!$style['include_exercises']) $sections[] = "Skip practice exercises section";
                if (!$style['include_tips']) $sections[] = "Skip tips & common mistakes section";
                if ($sections) $parts[] = implode(". ", $sections);
                if ($style['custom_instructions']) $parts[] = "Teacher instructions: {$style['custom_instructions']}";
                if ($parts) $styleNote = "\n\nIMPORTANT — The teacher has set these preferences for how lessons should be written:\n" . implode("\n", $parts) . "\nFollow these preferences closely.";
            }
        } catch (PDOException $e) {}

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

{$styleNote}

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

    case 'refine_lesson':
        // Load teacher style prefs
        $styleNote = "";
        try {
            $db = getDB();
            $ss = $db->prepare('SELECT tone, detail_level, custom_instructions FROM teacher_lesson_style WHERE teacher_id = ?');
            $ss->execute([$teacherId]);
            $st = $ss->fetch(PDO::FETCH_ASSOC);
            if ($st) {
                $pp = [];
                if ($st['tone']) $pp[] = "Tone: {$st['tone']}";
                if ($st['detail_level']) $pp[] = "Detail: {$st['detail_level']}";
                if ($st['custom_instructions']) $pp[] = "Teacher instructions: {$st['custom_instructions']}";
                if ($pp) $styleNote = "\nTeacher's style preferences: " . implode(". ", $pp);
            }
        } catch (PDOException $e) {}

        $contentId = trim($body['content_id'] ?? '');
        $currentHtml = $body['current_html'] ?? '';
        $feedback = trim($body['feedback'] ?? '');
        $title = trim($body['title'] ?? '');
        $track = trim($body['track'] ?? '');
        $type = trim($body['type'] ?? '');

        if (!$currentHtml || !$feedback) {
            jsonResponse(['error' => 'Current lesson HTML and feedback are required'], 400);
        }

        $refinePrompt = "You previously generated a lesson document for a piano teaching app. The teacher has reviewed it and wants changes.

Title: \"{$title}\"
" . ($track ? "Track: $track\n" : "") . ($type ? "Type: $type\n" : "") . "

Current lesson HTML:
{$currentHtml}

The teacher's feedback / requested changes:
\"{$feedback}\"

{$styleNote}

Please regenerate the lesson HTML incorporating the teacher's feedback. Keep the same overall structure and style guidelines (h2 for sections, h3 for subsections, ol/ul for lists, piano-tip and practice-box div classes for callouts). Apply the teacher's requested changes while preserving what was good about the original.

Respond with a JSON object:
{
  \"lesson_html\": \"the updated HTML content\",
  \"summary\": \"one-line summary of what changed\"
}";

        $result = callClaude($MUSIC_SYSTEM . "\n\nFor this request, refine an existing HTML lesson document based on teacher feedback. Respond in valid JSON.", $refinePrompt, 4096);
        $parsed = json_decode($result, true);
        if (!$parsed) {
            preg_match('/\{.*\}/s', $result, $m);
            $parsed = json_decode($m[0] ?? '{}', true);
        }

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
        $selectedUrl = trim($body['selected_url'] ?? '');
        $artistSearch = trim($body['artist'] ?? '');
        if (!$title && !$selectedUrl) jsonResponse(['error' => 'Title required'], 400);

        // If a selected_url was provided, save it directly
        if ($selectedUrl) {
            if ($contentId) {
                $db = getDB();
                try { $db->exec('ALTER TABLE content ADD COLUMN cover_image_url VARCHAR(500) DEFAULT NULL'); } catch (PDOException $e) {}
                $stmt = $db->prepare('UPDATE content SET cover_image_url = ? WHERE id = ? AND teacher_id = ?');
                $stmt->execute([$selectedUrl, $contentId, $teacherId]);
            }
            jsonResponse(['cover_url' => $selectedUrl]);
            break;
        }

        // Search iTunes — title only (no genre, which returns backing tracks)
        $searchTerm = $artistSearch ? urlencode($title . ' ' . $artistSearch) : urlencode($title);
        $ch = curl_init("https://itunes.apple.com/search?term={$searchTerm}&media=music&limit=20");
        curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 15, CURLOPT_FOLLOWLOCATION => true]);
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        $allResults = [];
        $skipWords = ['backing track', 'karaoke', 'minus one', 'play along', 'play-along', 'instrumental version', 'made famous', 'in the style', 'tribute to', 'cover version'];

        if ($httpCode === 200 && $response) {
            $data = json_decode($response, true);
            foreach (($data['results'] ?? []) as $r) {
                $art = $r['artworkUrl100'] ?? '';
                if (!$art) continue;
                $artist = $r['artistName'] ?? '';
                $album = $r['collectionName'] ?? '';
                $combined = strtolower($artist . ' ' . $album);

                // Filter out backing tracks, karaoke, etc
                $skip = false;
                foreach ($skipWords as $sw) {
                    if (strpos($combined, $sw) !== false) { $skip = true; break; }
                }
                if ($skip) continue;

                $allResults[] = [
                    'url' => str_replace('100x100bb', '600x600bb', $art),
                    'thumb' => str_replace('100x100bb', '200x200bb', $art),
                    'artist' => $artist,
                    'album' => $album,
                    'track' => $r['trackName'] ?? '',
                ];
            }
        }

        // Deduplicate by album artwork URL
        $seen = [];
        $unique = [];
        foreach ($allResults as $r) {
            if (!in_array($r['url'], $seen)) {
                $seen[] = $r['url'];
                $unique[] = $r;
            }
        }

        jsonResponse(['choices' => array_slice($unique, 0, 12)]);
        break;

    // ─── AI Recording Feedback ───
    case 'recording_feedback':
        $studentId = trim($body['student_id'] ?? '');
        $contentTitle = trim($body['content_title'] ?? '');
        $contentDescription = trim($body['content_description'] ?? '');
        $contentType = trim($body['content_type'] ?? '');
        $stepNotes = trim($body['step_notes'] ?? '');
        $studentLevel = trim($body['student_level'] ?? '');
        $selfAssessment = trim($body['self_assessment'] ?? '');

        if (!$contentTitle) jsonResponse(['error' => 'content_title required'], 400);

                $feedbackPrompt = 'You are an AI practice coach for a piano student. The student just finished recording themselves practising. Generate helpful, specific feedback and listening guidance.

Practice context:
- Content: "' . $contentTitle . '"
- Type: ' . $contentType . '
- Instructions: "' . $contentDescription . '"
' . ($stepNotes ? '- Teacher notes for this step: "' . $stepNotes . '"
' : '') . ($studentLevel ? '- Student level: ' . $studentLevel . '
' : '') . ($selfAssessment ? '- Student self-assessment: "' . $selfAssessment . '"
' : '') . '

Since you cannot listen to the audio, provide:
1. Listening Guide — 3-4 specific things the student should listen for when playing back their recording, tailored to this specific piece/exercise.

2. Common Pitfalls — 2-3 common issues students encounter with this type of material at this level.

3. Practice Tips — 2-3 targeted suggestions for how to improve based on the content type.

4. Self-Check Questions — 3 yes/no questions the student can answer honestly after listening to their recording.

Keep the tone encouraging and constructive. Use the student practice context to make advice specific, not generic.

Respond with a JSON object:
{
  "feedback_html": "the complete feedback as clean HTML (h3 for sections, ul/li for lists, p for paragraphs)",
  "headline": "one encouraging line"
}';

        $result = callClaude($MUSIC_SYSTEM, $feedbackPrompt, 1536);
        $parsed = json_decode($result, true);
        if (!$parsed) {
            preg_match('/\{.*\}/s', $result, $m);
            $parsed = json_decode($m[0] ?? '{}', true);
        }
        jsonResponse(['feedback' => $parsed]);
        break;

    // ─── Smart practice suggestions for a student ───
    case 'suggest_practice':
        $studentId = trim($body['student_id'] ?? '');
        if (!$studentId) jsonResponse(['error' => 'student_id required'], 400);

        $db = getDB();

        // Student info
        $stmt = $db->prepare('SELECT name, level, notes FROM students WHERE id = ? AND teacher_id = ?');
        $stmt->execute([$studentId, $teacherId]);
        $student = $stmt->fetch();
        if (!$student) jsonResponse(['error' => 'Student not found'], 404);

        // All content library
        $stmt = $db->prepare('SELECT id, title, type, track, description FROM content WHERE teacher_id = ? ORDER BY title');
        $stmt->execute([$teacherId]);
        $library = $stmt->fetchAll();

        // What's already been assigned to this student
        $stmt = $db->prepare('
            SELECT ast.content_id, c.title, p.completed, p.feedback, p.practice_seconds
            FROM assignments a
            JOIN assignment_steps ast ON ast.assignment_id = a.id
            JOIN content c ON c.id = ast.content_id
            LEFT JOIN progress p ON p.assignment_id = a.id AND p.step_id = ast.id AND p.student_id = ?
            WHERE a.student_id = ? AND a.teacher_id = ?
            ORDER BY a.created_at DESC
        ');
        $stmt->execute([$studentId, $studentId, $teacherId]);
        $assignedHistory = $stmt->fetchAll();

        // Recent observations
        $stmt = $db->prepare('SELECT note, created_at FROM teacher_observations WHERE student_id = ? AND teacher_id = ? ORDER BY created_at DESC LIMIT 10');
        $stmt->execute([$studentId, $teacherId]);
        $observations = $stmt->fetchAll();

        // Learning preferences
        $stmt = $db->prepare('SELECT preferences FROM learning_profiles WHERE student_id = ?');
        $stmt->execute([$studentId]);
        $lp = $stmt->fetch();
        $preferences = $lp ? json_decode($lp['preferences'], true) : null;

        $dataPayload = json_encode([
            'student' => $student,
            'content_library' => $library,
            'assignment_history' => $assignedHistory,
            'observations' => array_map(fn($o) => ['note' => $o['note'], 'date' => $o['created_at']], $observations),
            'preferences' => $preferences,
        ], JSON_PRETTY_PRINT);

        $suggestPrompt = 'You are an intelligent practice planning assistant for a piano teacher. Based on the student profile, assignment history, teacher observations, and available content library, suggest what this student should work on next.

Here is all the data:

' . $dataPayload . '

Analyse what the student has already covered, what they found easy vs difficult (from feedback), and what gaps exist. Then create a suggested practice assignment.

Rules:
- Prefer content from the existing library (use the content id)
- You can suggest new content items too (mark source as new)
- Order steps in a pedagogically sound sequence (warm-up, new material, practice, review)
- Include 4-7 steps
- Consider the student level and preferences
- If they struggled with something (feedback like hard or need_help), include review of that material
- If they found things easy (easy, nailed_it), progress to harder material
- Do not repeatedly assign the same content unless it needs review

Respond with a JSON object:
{
  "rationale": "2-3 sentences explaining why you chose this path",
  "week_label": "a descriptive label for this week",
  "steps": [
    {
      "source": "library or new",
      "content_id": "id from library if source is library, null if new",
      "title": "title of the content item",
      "type": "Watch|Play|Practice|Listen|Review",
      "track": "Jazz|Contemporary|Foundation|Crossover",
      "notes": "step-specific notes for this student",
      "new_description": "full description if this is a new content item, null if from library"
    }
  ]
}';

        $result = callClaude($MUSIC_SYSTEM, $suggestPrompt, 2048);
        $parsed = json_decode($result, true);
        if (!$parsed) {
            preg_match('/\{.*\}/s', $result, $m);
            $parsed = json_decode($m[0] ?? '{}', true);
        }
        jsonResponse(['suggestion' => $parsed]);
        break;

    // ─── AI Practice Summary for a student ───
    case 'practice_summary':
        $studentId = trim($body['student_id'] ?? '');
        if (!$studentId) jsonResponse(['error' => 'student_id required'], 400);

        $db = getDB();

        // Gather student info
        $stmt = $db->prepare('SELECT name, level, notes FROM students WHERE id = ? AND teacher_id = ?');
        $stmt->execute([$studentId, $teacherId]);
        $student = $stmt->fetch();
        if (!$student) jsonResponse(['error' => 'Student not found'], 404);

        // Recent assignments with steps
        $stmt = $db->prepare('
            SELECT a.id, a.week_label, a.created_at,
                   GROUP_CONCAT(DISTINCT c.title ORDER BY ast.sort_order SEPARATOR " | ") AS step_titles
            FROM assignments a
            LEFT JOIN assignment_steps ast ON ast.assignment_id = a.id
            LEFT JOIN content c ON c.id = ast.content_id
            WHERE a.student_id = ? AND a.teacher_id = ?
            ORDER BY a.created_at DESC LIMIT 8
        ');
        $stmt->execute([$studentId, $teacherId]);
        $recentAssignments = $stmt->fetchAll();

        // Progress with feedback + practice time
        $stmt = $db->prepare('
            SELECT p.assignment_id, p.step_id, p.completed, p.feedback, p.feedback_note,
                   p.practice_seconds, p.completed_at, c.title AS content_title
            FROM progress p
            LEFT JOIN assignment_steps ast ON ast.id = p.step_id
            LEFT JOIN content c ON c.id = ast.content_id
            WHERE p.student_id = ?
            ORDER BY p.completed_at DESC LIMIT 50
        ');
        $stmt->execute([$studentId]);
        $recentProgress = $stmt->fetchAll();

        // Teacher observations
        $stmt = $db->prepare('SELECT note, created_at FROM teacher_observations WHERE student_id = ? AND teacher_id = ? ORDER BY created_at DESC LIMIT 15');
        $stmt->execute([$studentId, $teacherId]);
        $observations = $stmt->fetchAll();

        // Learning preferences
        $stmt = $db->prepare('SELECT preferences FROM learning_profiles WHERE student_id = ?');
        $stmt->execute([$studentId]);
        $learningProfile = $stmt->fetch();
        $preferences = $learningProfile ? json_decode($learningProfile['preferences'], true) : null;

        // Student check-ins (mood/confidence)
        $stmt = $db->prepare('SELECT mood, confidence, note, created_at FROM student_checkins WHERE student_id = ? ORDER BY created_at DESC LIMIT 10');
        $stmt->execute([$studentId]);
        $checkins = $stmt->fetchAll();

        // Build the data summary for the AI
        $totalPracticeSeconds = array_sum(array_column($recentProgress, 'practice_seconds'));
        $completedSteps = count(array_filter($recentProgress, fn($p) => $p['completed']));
        $feedbackCounts = [];
        foreach ($recentProgress as $p) {
            if ($p['feedback']) $feedbackCounts[$p['feedback']] = ($feedbackCounts[$p['feedback']] ?? 0) + 1;
        }

        $dataPayload = json_encode([
            'student' => $student,
            'assignments' => $recentAssignments,
            'progress_summary' => [
                'total_practice_minutes' => round($totalPracticeSeconds / 60),
                'completed_steps' => $completedSteps,
                'feedback_breakdown' => $feedbackCounts,
            ],
            'recent_feedback' => array_map(fn($p) => [
                'title' => $p['content_title'],
                'feedback' => $p['feedback'],
                'note' => $p['feedback_note'],
                'practice_mins' => round(($p['practice_seconds'] ?? 0) / 60, 1),
                'date' => $p['completed_at'],
            ], array_slice(array_filter($recentProgress, fn($p) => $p['feedback']), 0, 20)),
            'observations' => array_map(fn($o) => ['note' => $o['note'], 'date' => $o['created_at']], $observations),
            'checkins' => $checkins,
            'preferences' => $preferences,
        ], JSON_PRETTY_PRINT);

        $summaryPrompt = 'You are generating a weekly AI practice summary for a piano teacher about one of their students. This summary helps the teacher quickly understand how their student is progressing and what to focus on in the next lesson.

Here is all the available data about this student:

' . $dataPayload . '

Generate a concise, insightful practice summary with these sections:

1. Overview (2-3 sentences) - Overall picture of how the student is doing this period. Mention practice time, completion rate, and general trajectory.

2. Strengths (2-3 bullet points) - What is going well based on feedback, observations, and completion patterns.

3. Areas for Focus (2-3 bullet points) - What needs attention. Be specific about which content items or skills show difficulty.

4. Mood and Engagement (1-2 sentences) - If check-in data is available, note any patterns in confidence or mood. If not, skip this section.

5. Suggested Next Steps (2-3 specific, actionable suggestions) - What the teacher should consider for the next lesson or assignment.

Keep the tone professional but warm. Use the student name. If data is sparse, acknowledge that and focus on what you can infer.

Respond with a JSON object:
{
  "summary_html": "the complete summary as clean HTML (use h3 for section titles, ul/li for lists, p for paragraphs)",
  "headline": "one-line headline summarising the student status"
}';

        $result = callClaude($MUSIC_SYSTEM, $summaryPrompt, 2048);
        $parsed = json_decode($result, true);
        if (!$parsed) {
            preg_match('/\{.*\}/s', $result, $m);
            $parsed = json_decode($m[0] ?? '{}', true);
        }
        jsonResponse(['summary' => $parsed]);
        break;

    default:
        jsonResponse(['error' => 'Invalid action. Use: generate_content, expand_shorthand, expand_observation, youtube_import, youtube_refine, youtube_bulk_import, build_path, bulk_generate, generate_lesson, refine_lesson, fix_enharmonics, generate_cover_art, practice_summary'], 400);
}
