<p align="center"><img width="300" src="/art/logo.svg" alt="Livewire Logo"></p>

<p align="center">
    <a href="https://packagist.org/packages/livewire/livewire">
        <img src="https://poser.pugx.org/livewire/livewire/d/total.svg" alt="Total Downloads">
    </a>
    <a href="https://packagist.org/packages/livewire/livewire">
        <img src="https://poser.pugx.org/livewire/livewire/v/stable.svg" alt="Latest Stable Version">
    </a>
    <a href="https://packagist.org/packages/livewire/livewire">
        <img src="https://poser.pugx.org/livewire/livewire/license.svg" alt="License">
    </a>
</p>

## Introduction

Livewire is a full-stack framework for Laravel that allows you to build dynamic UI components without leaving PHP.

## Try it yourself

If you wanna test out the changes this is how you do it:

```bash
git clone -b native-upload-chunking https://github.com/dev-idkwhoami/livewire.git
```

In the cloned repository root you run:

```bash
npm install
npm run build
```

In a project of your choosing add the following into your `repositories` section:

```json
        "livewire/livewire": {
            "type": "path",
            "url": "path\\to\\cloned\\livewire",
            "options": {
                "symlink": true
            }
        }
```

Then run a quick update to "link" the repository:
```bash
composer update
```


## Official Documentation

You can read the official documentation on the [Livewire website](https://livewire.laravel.com/docs).

## Contributing
<a name="contributing"></a>

Thank you for considering contributing to Livewire! You can read the contribution guide [here](.github/CONTRIBUTING.md).

## Code of Conduct
<a name="code-of-conduct"></a>

In order to ensure that the Laravel community is welcoming to all, please review and abide by Laravel's [Code of Conduct](https://laravel.com/docs/contributions#code-of-conduct).

## Security Vulnerabilities
<a name="security-vulnerabilities"></a>

Please review [our security policy](https://github.com/livewire/livewire/security/policy) on how to report security vulnerabilities.

## License
<a name="license"></a>

Livewire is open-sourced software licensed under the [MIT license](LICENSE.md).
