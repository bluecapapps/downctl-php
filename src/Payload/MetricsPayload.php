<?php

declare(strict_types=1);

namespace Bluecapapps\Downctl\Payload;

final class MetricsPayload
{
    public function __construct(
        public readonly float $cpuPercent,
        public readonly float $memoryPercent,
        public readonly int $memoryTotalMb,
        public readonly int $memoryUsedMb,
        public readonly float $diskPercent,
        public readonly int $diskTotalGb,
        public readonly int $diskUsedGb,
        public readonly ?float $loadAvg1m = null,
        public readonly ?float $loadAvg5m = null,
        public readonly ?float $loadAvg15m = null,
    ) {}

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return array_filter([
            'cpu_percent'     => $this->cpuPercent,
            'memory_percent'  => $this->memoryPercent,
            'memory_total_mb' => $this->memoryTotalMb,
            'memory_used_mb'  => $this->memoryUsedMb,
            'disk_percent'    => $this->diskPercent,
            'disk_total_gb'   => $this->diskTotalGb,
            'disk_used_gb'    => $this->diskUsedGb,
            'load_avg_1m'     => $this->loadAvg1m,
            'load_avg_5m'     => $this->loadAvg5m,
            'load_avg_15m'    => $this->loadAvg15m,
        ], fn ($v) => $v !== null);
    }
}
