package contracts

import (
	"sconcur/internal/dto"
)

type MessageHandler interface {
	Handle(task *dto.Task)
}
