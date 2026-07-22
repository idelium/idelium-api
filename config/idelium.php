<?php

return [
    'client_port' => env('IDELIUM_CL_PORT', 443),
    'result_payload_max_bytes' => env('IDELIUM_RESULT_PAYLOAD_MAX_BYTES', 1048576),
    'artifact_inline_max_bytes' => env('IDELIUM_ARTIFACT_INLINE_MAX_BYTES', 262144),
    'artifact_collection_max_items' => env('IDELIUM_ARTIFACT_COLLECTION_MAX_ITEMS', 50),
];
