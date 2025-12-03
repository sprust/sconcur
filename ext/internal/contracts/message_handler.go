package contracts

import (
	"context"
	"sconcur/internal/dto"
)

type MessageHandler interface {
	Handle(ctx context.Context, message *dto.Message) *dto.Result
}
