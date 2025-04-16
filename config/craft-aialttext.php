<?php

return [
    'openAiApiKey' => getenv('OPENAI_API_KEY'),
    'titlePrompt' => 'Generate a descriptive title for this image that would be appropriate for a website. The title should be concise but descriptive, and should not include any special characters or formatting.',
    'filenamePrompt' => 'Generate a SEO-friendly filename for this image. The filename should be descriptive, use hyphens to separate words, and be appropriate for a website. Do not include any special characters or file extensions.',
]; 