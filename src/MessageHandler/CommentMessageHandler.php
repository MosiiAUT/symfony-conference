<?php

namespace App\MessageHandler;

use App\Message\CommentMessage;
use App\Repository\CommentRepository;
use App\SpamChecker;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Handler\MessageHandlerInterface;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Workflow\Workflow;
use Symfony\Component\DependencyInjection\Attribute\Target;

class CommentMessageHandler implements MessageHandlerInterface
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private SpamChecker            $spamChecker,
        private CommentRepository      $commentRepository,
        private MessageBusInterface    $bus,
        #[Target('comment.state_machine')]
        private Workflow               $workflow,
        private LoggerInterface        $logger
    )
    {
    }

    public function __invoke(CommentMessage $message)
    {
        $comment = $this->commentRepository->find($message->id);
        if (!$comment) {
            return;
        }

        if ($this->workflow->can($comment, 'accept')) {
            $score = $this->spamChecker->getSpamScore($comment, $message->context);
            $transition = match ($score) {
                2 => 'reject_spam',
                1 => 'might_be_spam',
                0 => 'accept',
            };
            $this->workflow->apply($comment, $transition);
            $this->entityManager->flush();

            $this->bus->dispatch($message);
        } elseif ($this->workflow->can($comment, 'publish') || $this->workflow->can($comment, 'publish_ham')) {
            $this->workflow->apply($comment, $this->workflow->can($comment, 'publish') ? 'publish' : 'publish_ham');
            $this->entityManager->flush();
        } elseif ($this->logger) {
            $this->logger->debug('Dropping comment message', ['comment' => $comment->getId(), 'state' => $comment->getState()]);
        }
        $this->entityManager->flush();
    }
}