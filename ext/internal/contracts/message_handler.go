package contracts

import (
	"sconcur/internal/tasks"
)

type MessageHandler interface {
	Handle(task *tasks.Task)
}
