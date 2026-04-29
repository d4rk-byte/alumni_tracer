<?php

namespace App\Command;

use App\Repository\SurveyCampaignRepository;
use App\Service\SurveyCampaignDispatchService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

#[AsCommand(
    name: 'app:survey-campaigns:dispatch-due',
    description: 'Dispatches scheduled survey campaigns whose send time has arrived.',
)]
class DispatchDueSurveyCampaignsCommand extends Command
{
    public function __construct(
        private SurveyCampaignRepository $campaignRepository,
        private SurveyCampaignDispatchService $dispatchService,
        private UrlGeneratorInterface $urlGenerator,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $dueCampaigns = $this->campaignRepository->findDueScheduled(new \DateTimeImmutable());

        if ($dueCampaigns === []) {
            $io->success('No scheduled survey campaigns are due for dispatch.');

            return Command::SUCCESS;
        }

        $baseUrl = $this->resolveBaseUrl();
        $dispatched = 0;
        $skipped = 0;
        $failed = 0;

        foreach ($dueCampaigns as $campaign) {
            try {
                $recipientCount = $this->dispatchService->dispatchCampaign($campaign, $baseUrl);

                if ($recipientCount === 0) {
                    ++$skipped;
                    $io->warning(sprintf(
                        'Campaign "%s" is due but currently has no eligible recipients. It remains scheduled and will be retried on the next run.',
                        $campaign->getName()
                    ));

                    continue;
                }

                ++$dispatched;
                $io->writeln(sprintf(
                    'Queued %d invitation(s) for scheduled campaign "%s".',
                    $recipientCount,
                    $campaign->getName()
                ));
            } catch (\Throwable $exception) {
                ++$failed;
                $io->error(sprintf(
                    'Failed to dispatch scheduled campaign "%s": %s',
                    $campaign->getName(),
                    $exception->getMessage()
                ));
            }
        }

        if ($failed > 0) {
            $io->warning(sprintf(
                'Dispatch completed with issues: %d sent, %d skipped, %d failed.',
                $dispatched,
                $skipped,
                $failed
            ));

            return Command::FAILURE;
        }

        $io->success(sprintf(
            'Scheduled dispatch complete: %d campaign(s) sent, %d skipped.',
            $dispatched,
            $skipped
        ));

        return Command::SUCCESS;
    }

    private function resolveBaseUrl(): string
    {
        $homeUrl = $this->urlGenerator->generate('app_home', [], UrlGeneratorInterface::ABSOLUTE_URL);
        $parts = parse_url($homeUrl);

        if (!is_array($parts) || !isset($parts['scheme'], $parts['host'])) {
            return rtrim($homeUrl, '/');
        }

        $port = isset($parts['port']) ? ':' . $parts['port'] : '';

        return sprintf('%s://%s%s', $parts['scheme'], $parts['host'], $port);
    }
}