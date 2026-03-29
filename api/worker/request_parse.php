<?php
/**
 * Разбор тела запроса worker API: gzip (как у hive-agent), JSON, опционально msgpack (расширение PHP).
 *
 * @return array<string,mixed> тело как у json_decode для корня POST (method, params, …)
 */
function hive_worker_parse_request_body(string $rawBody, ?string $contentType, ?string $contentEncoding): array
{
    if ($rawBody === '') {
        return [];
    }
    $ct = strtolower((string) $contentType);
    $ce = strtolower((string) $contentEncoding);

    if ($ce !== '' && strpos($ce, 'gzip') !== false) {
        $gz = @gzdecode($rawBody);
        if ($gz !== false) {
            $rawBody = $gz;
        }
    }

    if (strpos($ct, 'msgpack') !== false && function_exists('msgpack_unpack')) {
        $u = @msgpack_unpack($rawBody);
        if (is_array($u)) {
            return $u;
        }
    }

    $json = json_decode($rawBody, true);

    return is_array($json) ? $json : [];
}
