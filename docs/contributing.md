---
title: Contributing
nav_order: 7
description: "Guidelines for contributing to the Haxinator 2000 project"
---

# Contributing to Haxinator 2000

Thank you for your interest in contributing to Haxinator 2000! This document provides guidelines and instructions for contributing to the project.

## Code of Conduct

By participating in this project, you agree to abide by our code of conduct:

- Be respectful and inclusive
- Provide constructive feedback
- Focus on what is best for the community
- Show empathy towards other community members

## Ways to Contribute

There are many ways to contribute to Haxinator 2000:

1. **Code contributions**: Add new features or fix bugs
2. **Documentation**: Improve or expand the documentation
3. **Bug reports**: Submit detailed bug reports
4. **Feature requests**: Suggest new features or improvements
5. **Testing**: Test the software on different hardware or in different environments
6. **Community support**: Help answer questions from other users

## Getting Started

### Setting Up Your Development Environment

1. Fork the repository on GitHub
2. Clone your fork locally:
   ```bash
   git clone https://github.com/morehax/haxinator.git
   cd haxinator
   ```
3. Set up the upstream remote:
   ```bash
   git remote add upstream https://github.com/morehax/haxinator.git
   ```
4. Install the development dependencies as described in the [Building Custom Images](custom-images.md) documentation

### Development Workflow

1. Create a new branch for your work:
   ```bash
   git checkout -b feature/your-feature-name
   ```
   
2. Make your changes, following our coding standards

3. Write or update tests as necessary

4. Run tests locally to ensure your changes work as expected

5. Commit your changes with a clear commit message:
   ```bash
   git commit -m "Add feature: description of your feature"
   ```

6. Push your branch to your fork:
   ```bash
   git push origin feature/your-feature-name
   ```

7. Create a pull request from your fork to the main repository

## Pull Request Guidelines

When submitting a pull request:

1. Fill out the pull request template completely
2. Link to any relevant issues
3. Include screenshots or screen recordings for UI changes
4. Update documentation to reflect your changes
5. Make sure all tests pass
6. Keep your PR updated with changes from the main branch

## Coding Standards

- Follow the existing code style and conventions
- Write clear, commented, and testable code
- Keep functions small and focused
- Prioritize security and performance

## Documentation Guidelines

When updating documentation:

1. Use clear, concise language
2. Follow the established structure
3. Include examples where appropriate
4. Test any commands or code snippets you include
5. Check for spelling and grammar errors

## Bug Reports and Feature Requests

When submitting a bug report or feature request:

1. Use the provided issue templates
2. Include as much detail as possible
3. For bugs, include steps to reproduce, expected behavior, and actual behavior
4. For feature requests, explain the use case and benefits

## Testing

- All new features should include appropriate tests
- Run the test suite before submitting your PR
- Consider testing on different Raspberry Pi models if possible

## Community

Join our community channels to discuss the project and get help:

- GitHub Discussions
- Discord Server (link TBD)
- Regular online community calls (schedule TBD)

## License

By contributing to Haxinator 2000, you agree that your contributions will be licensed under the project's MIT License.
