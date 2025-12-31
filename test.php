<?php

public class UserController
{
    public function register(Request $request): Response
    {

        $userDto = new UserDto($request->getBody());
        
        if (!$userDto->isValid()) {
            return new Response(
                400,
                'Invalid user data'
            );
        }

        try {
            $this->registerUserUseCase->execute($dto);
        } catch (UseCaseException $e) {
            return new Response(500, 'User registration failed');
        }

        return new Response(
            201,
            'User registered successfully'
        );
    }
}

public class RegisterUserUseCase
{
    private array $errors = [];

    public function __construct(
        private UserRepository $userRepository,
        private Notifer $notifer,
    ) {}

    public function execute(UserDto $userDto): void
    {
        if ($this->userRepository->existsByEmail(
            new Email($userDto->getEmail()))
        ) {
            $this->errors[] = 'Email already in use.';
            throw new ServiceException('Emailが既に使用されています。');
        }

        $user = User::factory($userDto);
        $this->userRepository->save($user);
        $this->notifer->notify(...);
    }

    public function hasErrors(): bool
    {
        return !empty($this->errors);
    }
}

public class User
{
    public function __construct(
        private Username $username,
        private Email $email,
        private Password $passwordHash,
    ) {}

    public static function factory(UserDto $userDto): self
    {
        return new self(
            new Username($userDto->getUsername()),
            new Email($userDto->getEmail()),
            new Password($userDto->getPassword())->hash,
        );
    }
}

public class Username
{
    private string $value;
    public function __construct(private string $value)
    {
        if (strlen($value) < 3) {
            throw new ValidationException(
                'ユーザ名は3文字以上である必要があります。'
            );
        }
    }

    public function getValue(): string
    {
        return $this->value;
    }
}

public interface UserRepository
{
    public function save(User $user): void;
    public function existsByEmail(Email $email): bool;
}

public class UserRepositoryImpl implements UserRepository
{
    public function save(User $user): void
    {
        Cake::table('users')->save($user);
    }

    public function existsByEmail(Email $email): bool
    {
        $count = Cake::table('users')
            ->where(['email' => $email->getValue()])
            ->count();
        return $count > 0;
    }
}


public interface Notifer
{
    public function notify(string $message): void;
}

public class EmailNotifier implements Notifer
{
    public function notify(string $message): void
    {
        Cake::mailer()->send(...);
    }
}

