.PHONY: build setup-hooks

build:
	cp app/assets/styles/app.css assets/styles/app.css
	npx encore production

setup-hooks:
	mkdir -p .git/hooks
	cp .githooks/pre-commit .git/hooks/pre-commit
	chmod +x .git/hooks/pre-commit
	@echo "✓ Git hook pre-commit installé."
