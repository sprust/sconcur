package contracts

import (
	"sconcur/internal/tasks"
)

type FeatureContract interface {
	Handle(task *tasks.Task)
}
